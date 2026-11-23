<?php

namespace KodmobTools\Nobet\Tests;

use App\Core\Mail\SiteMail;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\Veri\Donguler;
use KodmobTools\Nobet\Veri\Uyeler;
use Tests\TestCase;

/** Eczacı hesabı: eczaneye bağlama, Nöbetlerim, mazeret talebi, geri alma, yöneticinin kararı, yetki sınırları. */
class EczacimTest extends TestCase
{
    private const API = '/v1/admin/nobet';

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_bolgeler')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    /** @return array{0: int, 1: int, 2: array<string, string>} eczane A, eczane B, eczacının başlığı */
    private function hazirla(): array
    {
        $h = $this->admin();
        Uyeler::roller();
        $bolge = $this->postJson(self::API.'/eski/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $a = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'GÜNEŞ', 'bolge_id' => $bolge]], $h)->json('id');
        $b = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'YONCA', 'bolge_id' => $bolge]], $h)->json('id');
        $bugun = substr(Clock::now(), 0, 10);
        foreach ([[$a, '-3 days'], [$a, '+5 days'], [$a, '+40 days'], [$b, '+6 days']] as [$e, $fark]) {
            DB::table('nobet_nobetler')->insert(['tarih' => date('Y-m-d', strtotime($bugun.' '.$fark)), 'vardiya' => 'tumgun', 'bolge_id' => $bolge,
                'eczane_id' => $e, 'ozel_gun' => false, 'kaynak' => 'elle', 'created_at' => now(), 'updated_at' => now()]);
        }
        $uye = $this->postJson(self::API.'/eski/uyeler', ['body' => ['adi' => 'Ayşe Eczacı', 'eposta' => 'ayse@eczane.test', 'sifre' => 'Nobet-Sifresi-2026', 'rol' => 0, 'eczane_id' => $a]], $h)
            ->assertOk()->assertJson(['eczane_id' => $a, 'eczane_adi' => 'GÜNEŞ'])->json('id');
        $this->assertSame($uye, (int) DB::table('nobet_eczaneler')->where('id', $a)->value('kullanici_id'));

        return [$a, $b, $this->as(Uyeler::ECZACI, 'ayse')];
    }

    public function test_eczaci_kendi_gelecek_nobetlerini_gorur(): void
    {
        [, , $ec] = $this->hazirla();
        $ozet = $this->getJson(self::API.'/benim', $ec)->assertOk()->json();
        $this->assertSame('GÜNEŞ', $ozet['eczane']['ad']);
        $this->assertCount(2, $ozet['nobetler']);
        $this->assertSame(1, $ozet['gecmis_nobet']);
    }

    public function test_talep_karar_ve_geri_alma(): void
    {
        [$a, , $ec] = $this->hazirla();
        $h = $this->admin();
        $gun = date('Y-m-d', strtotime(substr(Clock::now(), 0, 10).' +5 days'));

        $this->postJson(self::API.'/benim/mazeret', ['tarih' => '2020-01-01', 'aciklama' => 'Eski gün'], $ec)->assertStatus(422)->assertJsonPath('details.fields.tarih', 'Geçmiş bir gün için mazeret bildirilemez.');
        $id = $this->postJson(self::API.'/benim/mazeret', ['tarih' => $gun, 'aciklama' => 'Hastane randevusu'], $ec)->assertOk()->json('id');
        $this->postJson(self::API.'/benim/mazeret', ['tarih' => $gun, 'aciklama' => 'Yine'], $ec)->assertStatus(409);
        $this->assertSame(['talep', $a], [DB::table('nobet_mazeretler')->where('id', $id)->value('durum'), (int) DB::table('nobet_mazeretler')->where('id', $id)->value('eczane_id')]);

        // Yönetici Mazeretler listesinde eczane adıyla görür; eczacı karar veremez.
        $this->getJson(self::API.'/eski/mazeretler', $h)->assertOk()->assertJsonPath('0.eczane_adi', 'GÜNEŞ')->assertJsonPath('0.durum', 'talep');
        $this->postJson(self::API."/mazeretler/{$id}/karar", ['karar' => 'onay'], $ec)->assertForbidden();
        $this->postJson(self::API."/mazeretler/{$id}/karar", ['karar' => 'belki'], $h)->assertStatus(422);
        $this->postJson(self::API."/mazeretler/{$id}/karar", ['karar' => 'ret', 'not' => 'Yerine bakacak eczane yok'], $h)->assertOk()->assertJson(['karar' => 'ret']);
        $this->postJson(self::API."/mazeretler/{$id}/karar", ['karar' => 'onay'], $h)->assertStatus(409);

        $m = $this->getJson(self::API.'/benim', $ec)->json('mazeretler.0');
        $this->assertSame(['ret', 'Yerine bakacak eczane yok'], [$m['durum'], $m['karar_notu']]);
        $this->deleteJson(self::API."/benim/mazeret/{$id}", [], $ec)->assertStatus(409);

        $id2 = $this->postJson(self::API.'/benim/mazeret', ['tarih' => $gun, 'aciklama' => 'Yeni randevu'], $ec)->assertOk()->json('id');
        $this->deleteJson(self::API."/benim/mazeret/{$id2}", [], $ec)->assertOk();
        $this->assertDatabaseMissing('nobet_mazeretler', ['id' => $id2]);
    }

    public function test_baska_eczanenin_talebi_ve_bagsiz_hesap(): void
    {
        [, $b, $ec] = $this->hazirla();
        $h = $this->admin();
        // Başka eczanenin talebi geri alınamaz.
        $diger = DB::table('nobet_mazeretler')->insertGetId(['tarih' => '2030-01-01', 'aciklama' => 'x', 'tur' => 'mazeret', 'eczane_id' => $b, 'durum' => 'talep', 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson(self::API."/benim/mazeret/{$diger}", [], $ec)->assertNotFound();

        // Bağı kaldırılan eczacı: özet boş, talep 403.
        $uye = User::query()->where('email', 'ayse@eczane.test')->value('id');
        $this->putJson(self::API."/eski/uyeler/{$uye}", ['body' => ['eczane_id' => '']], $h)->assertOk()->assertJson(['eczane_id' => null]);
        $this->getJson(self::API.'/benim', $ec)->assertOk()->assertJson(['eczane' => null, 'nobetler' => []]);
        $this->postJson(self::API.'/benim/mazeret', ['tarih' => '2030-01-02', 'aciklama' => 'Randevu'], $ec)->assertForbidden();

        // Eczaneyi başka üyeye bağlamak öncekinin bağını koparır (bir eczane bir kullanıcı).
        $this->putJson(self::API."/eski/uyeler/{$uye}", ['body' => ['eczane_id' => $b]], $h)->assertOk()->assertJson(['eczane_adi' => 'YONCA']);
        $ikinci = $this->postJson(self::API.'/eski/uyeler', ['body' => ['adi' => 'Mehmet', 'eposta' => 'm@eczane.test', 'sifre' => 'Nobet-Sifresi-2026', 'eczane_id' => $b]], $h)->assertOk()->json('id');
        $this->assertSame($ikinci, (int) DB::table('nobet_eczaneler')->where('id', $b)->value('kullanici_id'));
        $this->getJson(self::API."/eski/uyeler/{$uye}", $h)->assertJson(['eczane_id' => null]);
    }

    public function test_talep_ve_karar_bildirimi(): void
    {
        Mail::fake();
        [, , $ec] = $this->hazirla();
        $h = $this->admin();
        $yonetici = User::query()->where('role', 'admin')->value('id');
        $eczaci = User::query()->where('username', 'ayse')->value('id');
        $oda = $this->postJson(self::API.'/eski/uyeler', ['body' => ['adi' => 'Oda Başkanı', 'eposta' => 'baskan@oda.test', 'sifre' => 'Nobet-Sifresi-2026', 'rol' => 1]], $h)->assertOk()->json('id');
        $gun = date('Y-m-d', strtotime(substr(Clock::now(), 0, 10).' +5 days'));

        $id = $this->postJson(self::API.'/benim/mazeret', ['tarih' => $gun, 'aciklama' => 'Hastane randevusu'], $ec)->assertOk()->json('id');
        // Nöbeti yönetenlere (sistem yöneticisi ve oda yöneticisi) zil; eczacının kendisine değil.
        foreach ([$yonetici, $oda] as $kisi) {
            $this->assertDatabaseHas('notifications', ['user_id' => $kisi, 'type' => 'nobet.mazeret_talebi', 'title' => 'Mazeret talebi: GÜNEŞ', 'url' => '/admin/mazeret']);
        }
        $this->assertDatabaseMissing('notifications', ['user_id' => $eczaci]);
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('baskan@oda.test') && str_contains($m->subjectLine, 'Mazeret talebi: GÜNEŞ'));

        $this->postJson(self::API."/mazeretler/{$id}/karar", ['karar' => 'onay', 'not' => 'Yerinize YONCA bakacak'], $h)->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $eczaci, 'type' => 'nobet.mazeret_karari', 'title' => 'Mazeret talebiniz onaylandı', 'tone' => 'success', 'url' => '/admin/nobetlerim']);
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('ayse@eczane.test') && str_contains($m->subjectLine, 'onaylandı'));

        // Eczacı kendi bildirimini zilden okur.
        $this->getJson('/v1/notifications?limit=10', $ec)->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('items.0.title', 'Mazeret talebiniz onaylandı');
    }

    public function test_dongu_bitisi_hatirlatmasi(): void
    {
        [$a] = $this->hazirla();
        $bugun = substr(Clock::now(), 0, 10);
        $gun = static fn (int $fark) => date('Y-m-d', strtotime($bugun.' '.($fark >= 0 ? '+' : '').$fark.' days'));
        $donguler = new Donguler;
        $sayi = static fn (int|string|null $kisi) => DB::table('notifications')->where('type', 'nobet.dongu_bitiyor')->where('user_id', $kisi)->count();

        // Son nöbet günü 40 gün sonra: henüz erken.
        $this->assertNull($donguler->hatirlat($bugun));
        // 30 gün kala bir kez; ertesi gün tekrar etmez; 7 gün kala bir kez daha; liste bitince susar.
        $this->assertSame(['son_gun' => $gun(40), 'kalan' => 30, 'esik' => 30], $donguler->hatirlat($gun(10)));
        $this->assertNull($donguler->hatirlat($gun(11)));
        $this->assertSame(7, $donguler->hatirlat($gun(33))['esik'] ?? null);
        $this->assertNull($donguler->hatirlat($gun(35)));
        $this->assertNull($donguler->hatirlat($gun(41)));
        $this->assertSame(2, $sayi(User::query()->where('role', 'admin')->value('id')));
        // Eczacı (nöbeti yönetemez) almaz.
        $this->assertSame(0, $sayi(User::query()->where('username', 'ayse')->value('id')));

        // Liste uzatılınca (yeni son gün) yeniden başlar.
        DB::table('nobet_nobetler')->insert(['tarih' => $gun(63), 'vardiya' => 'tumgun', 'bolge_id' => DB::table('nobet_bolgeler')->value('id'), 'eczane_id' => $a, 'ozel_gun' => false, 'kaynak' => 'elle', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['son_gun' => $gun(63), 'kalan' => 30, 'esik' => 30], $donguler->hatirlat($gun(33)));
    }
}
