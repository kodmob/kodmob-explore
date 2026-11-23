<?php

namespace KodmobTools\Nobet\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\Veri\Aktarim;
use KodmobTools\Nobet\Veri\Nobetler;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Eski sistemden aktarım: eşlemeler, kayan tarih düzeltmesi, devir, pasif / grupsuz eczane, özel gün kurası, deneme kipi. */
class AktarimTest extends TestCase
{
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_bolgeler')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    /** Eski şemanın aktarımın okuduğu sütunları (SQLite; tarihler zaten +03:00 okunmuş gibi). */
    private function eskiVeritabani(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sayaclar = implode(', ', array_map(fn ($a) => "{$a}Sayisi INTEGER DEFAULT 0", [
            'pazartesi', 'sali', 'carsamba', 'persembe', 'cuma', 'cumartesi', 'pazar', 'ocak', 'subat', 'mart', 'nisan', 'mayis',
            'haziran', 'temmuz', 'agustos', 'eylul', 'ekim', 'kasim', 'aralik', 'kis', 'ilkbahar', 'yaz', 'sonbahar', 'haftaici', 'haftasonu',
        ]));
        $pdo->exec('CREATE TABLE bolgeler (id INTEGER PRIMARY KEY, bolge_title TEXT, ilce_id INTEGER)');
        $pdo->exec('CREATE TABLE gruplar (id INTEGER PRIMARY KEY, grup_title TEXT, grup_eslesme_ids TEXT, grup_yedek_id INTEGER, bolge_id INTEGER, ilce_id INTEGER)');
        $pdo->exec("CREATE TABLE eczaneler (id INTEGER PRIMARY KEY, adi TEXT, mahalle TEXT, cadde TEXT, binano TEXT, tel TEXT, ilce_id INTEGER, bolge_id INTEGER, grup_id TEXT, durum TEXT, latlong TEXT, {$sayaclar})");
        $pdo->exec('CREATE TABLE raporlar (id INTEGER PRIMARY KEY, eczane_id INTEGER, gun_adi TEXT, nobet_tipi TEXT, ozel_gun INTEGER, ilce_id INTEGER, bolge_id INTEGER, grup_id INTEGER, dongu_id INTEGER, tarih TEXT)');
        $pdo->exec('CREATE TABLE ozelgunler (id INTEGER PRIMARY KEY, adi TEXT, eczane_ids TEXT, ilce_id INTEGER, tarih TEXT)');
        $pdo->exec('CREATE TABLE donguler (id INTEGER PRIMARY KEY, adi TEXT, method TEXT, baslangic TEXT, bitis TEXT, ilce_id INTEGER, tarih TEXT)');

        $pdo->exec("INSERT INTO bolgeler VALUES (4, 'AB', 1423), (5, 'Doğu', 1423), (9, 'Başka oda', 80)");
        $pdo->exec("INSERT INTO gruplar VALUES (8, '11', '7', 7, 4, 1423), (7, '12', NULL, NULL, 4, 1423)");
        $pdo->exec("INSERT INTO eczaneler (id, adi, mahalle, cadde, binano, tel, ilce_id, bolge_id, grup_id, durum, latlong, cumartesiSayisi, pazarSayisi) VALUES
            (10, 'GÜNEŞ', 'Cumhuriyet Mah.', 'Atatürk Cad.', '12', '03125550101', 1423, 4, '8', '1', '37.1234, 36.5678', 5, 0),
            (12, 'YONCA', NULL, NULL, NULL, '0', 1423, 5, '0', '1', NULL, 0, 0),
            (13, 'KAPALI', NULL, NULL, NULL, '0328', 1423, 4, '7', '0', 'bozuk', 0, 0),
            (99, 'BAŞKA ODA', NULL, NULL, NULL, '0', 80, 9, '0', '1', NULL, 0, 0)");
        $pdo->exec("INSERT INTO donguler VALUES (3, 'Döngü 2025-07-01', 'A,B,C,D,E', '2025-07-01 00:00:00', '2025-07-11 00:00:00', 1423, '2025-06-30 10:00:00')");
        // 05.07.2025 cumartesi; 2. kayıt eski kayma hatasıyla bir gün geride (04.07 cuma görünüyor, gün adı cumartesi).
        $pdo->exec("INSERT INTO raporlar VALUES
            (1, 10, 'cumartesi', 'tumgun', 0, 1423, 4, 8, 3, '2025-07-05 00:00:00'),
            (2, 12, 'cumartesi', 'tumgun', 0, 1423, 5, 0, 3, '2025-07-04 00:00:00'),
            (3, 12, 'salı', 'tumgun', 1, 1423, 5, 0, NULL, '2025-07-15 00:00:00'),
            (4, 77, 'pazar', 'tumgun', 0, 1423, 4, 8, 3, '2025-07-06 00:00:00'),
            (5, 99, 'pazar', 'tumgun', 0, 80, 9, 0, NULL, '2025-07-06 00:00:00')");
        // Adres: 1423'ün iki mahallesi, başka ilçenin bir mahallesi; caddelerin ilçe kodu çoğunlukla boş (eski veri).
        $pdo->exec('CREATE TABLE mahalle (mahalle_id INTEGER PRIMARY KEY, mahalle_title TEXT, mahalle_key INTEGER, mahalle_ilcekey INTEGER, mahalle_sehirkey INTEGER)');
        $pdo->exec('CREATE TABLE sokak_cadde (sokak_cadde_id INTEGER PRIMARY KEY, sokak_cadde_title TEXT, sokak_cadde_mahallekey INTEGER, sokak_cadde_ilcekey INTEGER, sokak_cadde_sehirkey INTEGER)');
        $pdo->exec("INSERT INTO mahalle VALUES (1, 'DERE', 97324, 1423, 80), (2, '7 MART', 97230, 1423, 80), (3, 'BAŞKA', 11111, 1560, 80)");
        $pdo->exec("INSERT INTO sokak_cadde VALUES (1, 'ANDIRIN', 97324, NULL, NULL), (2, '451. SK.', 97230, NULL, NULL), (3, '452. SK.', 97230, 1423, 80), (4, 'UZAK', 11111, NULL, NULL)");
        $pdo->exec('CREATE TABLE mazeretler (id INTEGER PRIMARY KEY, aciklama TEXT, turu TEXT, gunu TEXT, ilce_id INTEGER, tarih TEXT)');
        $pdo->exec("INSERT INTO mazeretler VALUES (1, 'Eczacı hasta', 'mazeret', '2025-07-05 00:00:00', 1423, '2025-07-04 10:00:00'), (2, 'Başka oda', 'mazeret', '2025-07-05 00:00:00', 80, '2025-07-04 10:00:00')");
        // Üyeler: rol 1 yönetici, 2 sıradan; e-postasız atlanır. Şifre özeti olduğu gibi taşınır.
        $pdo->exec('CREATE TABLE uyeler (id INTEGER PRIMARY KEY, adi TEXT, eposta TEXT, telefon TEXT, sifre TEXT, aktivasyon INTEGER, rol INTEGER, tarih TEXT)');
        $sorgu = $pdo->prepare('INSERT INTO uyeler VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $sorgu->execute([9, 'Oda Başkanı', 'Baskan@Oda.test', '3125550101', password_hash('eski-sifre-9', PASSWORD_BCRYPT, ['cost' => 4]), 1, 1, '2024-01-02 10:00:00']);
        $sorgu->execute([10, 'Eczacı Bir', 'eczaci@oda.test', '', password_hash('eski-sifre-10', PASSWORD_BCRYPT, ['cost' => 4]), 1, 2, '2024-01-02 10:00:00']);
        $sorgu->execute([11, 'Postasız', '', '', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 1, 0, '2024-01-02 10:00:00']);
        $pdo->exec("INSERT INTO ozelgunler VALUES (36, '15 Temmuz', NULL, 1423, '2025-07-15 00:00:00'), (37, '', '10', 1423, '2025-07-17 00:00:00'), (38, 'Tekrar', NULL, 1423, '2025-07-17 00:00:00')");

        return $pdo;
    }

    public function test_yalniz_uyeler_dolu_siteye_aktarilir_tekrar_guvenli(): void
    {
        $aktarim = new Aktarim($this->eskiVeritabani());
        $aktarim->aktar(1423, false);
        DB::table('users')->whereIn('role', ['oda-yoneticisi', 'eczaci'])->delete();

        $this->assertSame(['uye' => 2], $aktarim->uyeleriAktar(false)['ozet']);
        $ikinci = $aktarim->uyeleriAktar(false);
        $this->assertSame([], $ikinci['ozet']);
        $this->assertCount(3, $ikinci['uyarilar']);
        $this->assertSame(2, DB::table('users')->whereIn('role', ['oda-yoneticisi', 'eczaci'])->count());
    }

    public function test_bir_odanin_verisi_eslenerek_aktarilir(): void
    {
        $sonuc = (new Aktarim($this->eskiVeritabani()))->aktar(1423, false);

        $this->assertEquals(['bolge' => 2, 'grup' => 2, 'eczane' => 2, 'pasif_eczane' => 1, 'devirli_eczane' => 1, 'duzeltilen_tarih' => 1,
            'ozel_gun' => 2, 'atlanan_ozel_gun' => 1, 'dongu' => 1, 'eczanesi_olmayan_rapor' => 1, 'nobet' => 3, 'ozel_gun_nobeti' => 1, 'mahalle' => 2, 'cadde' => 3, 'mazeret' => 1, 'uye' => 2], $sonuc['ozet']);
        $this->assertSame([['baskan', 'oda-yoneticisi', '0312 555 01 01'], ['eczaci', 'eczaci', '']],
            DB::table('users')->whereIn('role', ['oda-yoneticisi', 'eczaci'])->orderBy('id')->get()->map(fn ($u) => [$u->username, $u->role, (string) $u->phone])->all());
        // Eski şifreyle, e-postayla giriş.
        $this->postJson('/v1/auth/token', ['username' => 'baskan@oda.test', 'password' => 'eski-sifre-9'])->assertOk();
        $this->assertStringContainsString('#11', implode(' ', $sonuc['uyarilar']));
        $this->assertSame(['2025-07-05', 'Eczacı hasta'], [substr((string) DB::table('nobet_mazeretler')->value('tarih'), 0, 10), DB::table('nobet_mazeretler')->value('aciklama')]);
        $this->assertSame(['7 MART', 'DERE'], DB::table('nobet_mahalleler')->orderBy('ad')->pluck('ad')->all());
        $this->assertSame(['451. SK.', '452. SK.'], DB::table('nobet_caddeler')->where('mahalle_anahtar', 97230)->orderBy('ad')->pluck('ad')->all());
        $this->assertNotContains('Başka oda', DB::table('nobet_bolgeler')->pluck('ad')->all());

        $g11 = DB::table('nobet_gruplar')->where('ad', '11')->first();
        $g12 = (int) DB::table('nobet_gruplar')->where('ad', '12')->value('id');
        $this->assertSame([[$g12], $g12], [json_decode($g11->eslesme, true), (int) $g11->yedek_id]);

        $yedi = DB::table('nobet_eczaneler')->where('ad', 'GÜNEŞ')->first();
        $this->assertSame(['Cumhuriyet Mah. Atatürk Cad. 12', '0312 555 01 01', 37.1234, (int) $g11->id, ['cumartesi' => 4]],
            [$yedi->adres, $yedi->telefon, (float) $yedi->enlem, (int) $yedi->grup_id, json_decode($yedi->devir, true)]);
        $yonca = DB::table('nobet_eczaneler')->where('ad', 'YONCA')->first();
        $this->assertSame([null, null, null, 1], [$yonca->grup_id, $yonca->telefon, $yonca->enlem, (int) $yonca->aktif]);
        $this->assertSame(0, (int) DB::table('nobet_eczaneler')->where('ad', 'KAPALI')->value('aktif'));

        // Kayan tarih düzeltildi: iki kayıt da 05.07 cumartesi.
        $this->assertSame(['2025-07-05', '2025-07-05', '2025-07-15', '2025-07-17'],
            DB::table('nobet_nobetler')->orderBy('tarih')->pluck('tarih')->map(fn ($t) => substr($t, 0, 10))->all());
        $kura = DB::table('nobet_nobetler')->where('tarih', '2025-07-17')->first();
        $this->assertSame([(int) $yedi->id, 1, 'aktarim'], [(int) $kura->eczane_id, (int) $kura->ozel_gun, $kura->kaynak]);
        $this->assertSame(['15 Temmuz', 'Özel gün'], DB::table('nobet_ozel_gunler')->orderBy('tarih')->pluck('ad')->all());

        $dongu = DB::table('nobet_donguler')->first();
        $this->assertSame(['A,B,C,D,E', 2, '2025-07-01'], [$dongu->kurallar, (int) $dongu->atama_sayisi, substr($dongu->baslangic, 0, 10)]);
    }

    public function test_telefonlar_turkiye_bicimine_getirilir(): void
    {
        $this->assertSame(
            ['0312 555 01 01', '0312 555 02 02', '0555 444 33 22', '0532 111 22 33', null, null, null],
            array_map([Aktarim::class, 'telefon'], ['03125550101', '3125550202', '0555 444 33 22', '+90 532 111 22 33', '00', '0328', ''])
        );
    }

    public function test_aktarilan_gecmis_sonraki_dongude_sayilir(): void
    {
        (new Aktarim($this->eskiVeritabani()))->aktar(1423, false);
        $yedi = (int) DB::table('nobet_eczaneler')->where('ad', 'GÜNEŞ')->value('id');

        $sayaclar = (new Nobetler)->sayaclar();
        // 1 rapor (05.07 cumartesi) + 4 devir; özel gün nöbeti (17.07) sayılmaz.
        $this->assertSame([5, 1, 1], [$sayaclar->sayi($yedi, 'cumartesi'), $sayaclar->sayi($yedi, 'temmuz'), $sayaclar->sayi($yedi, 'haftasonu')]);
    }

    public function test_deneme_yazmaz_dolu_siteye_ve_belirsiz_ilceye_aktarmaz(): void
    {
        $sonuc = (new Aktarim($this->eskiVeritabani()))->aktar(1423, true);
        $this->assertSame(3, $sonuc['ozet']['nobet']);
        $this->assertSame(0, DB::table('nobet_eczaneler')->count());

        try {
            (new Aktarim($this->eskiVeritabani()))->aktar(null, true);
            $this->fail('iki ilçe varken seçim istenmeliydi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('birden çok ilçe', $e->getMessage());
        }

        (new Aktarim($this->eskiVeritabani()))->aktar(1423, false);
        $this->expectExceptionMessage('aktarım yalnızca boş siteye yapılır');
        (new Aktarim($this->eskiVeritabani()))->aktar(1423, false);
    }
}
