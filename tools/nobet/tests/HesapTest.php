<?php

namespace KodmobTools\Nobet\Tests;

use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Kodmob\Members\Organizations;
use KodmobTools\Nobet\Http\HesapController;
use KodmobTools\Nobet\Http\OdaController;
use KodmobTools\Nobet\NobetModule;
use KodmobTools\Nobet\Veri\Uyeler;
use Tests\TestCase;

/**
 * Eczacı üye panelinde (Kodmob 3.2 Hesabım): Nöbetlerim, mazeret, nöbet takası, Oda yönetimi (kurumun yöneticisi),
 * eczane ↔ üye bağı ve panel hesaplarından geçiş (`nobet:uyelere`).
 */
class HesapTest extends TestCase
{
    private const ENV = 'blog,briefs,members';

    private const API = '/v1/admin/nobet';

    /** @var array<string, array{0: int, 1: string}> ad → [üye kimliği, oturum çerezi] */
    private array $uyeler = [];

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\n", 200)]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_takaslar')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    private function gun(int $fark): string
    {
        return date('Y-m-d', strtotime(substr(Clock::now(), 0, 10).' '.($fark >= 0 ? '+' : '').$fark.' days'));
    }

    private function uye(string $ad, string $eposta): int
    {
        $res = $this->postJson('/v1/members/register', [
            'name' => $ad, 'email' => $eposta, 'password' => 'Guclu-sifre-2026', 'consent' => true, 'website' => '',
            'started_at' => (int) (microtime(true) * 1000) - 10000, 'locale' => 'tr',
        ])->assertOk();
        $id = (int) DB::table('members')->where('email', $eposta)->value('id');
        DB::table('members')->where('id', $id)->update(['email_verified_at' => Clock::now()]);
        $this->uyeler[$ad] = [$id, (string) $res->getCookie('km_member', false)?->getValue()];

        return $id;
    }

    private function olarak(string $ad): self
    {
        $this->flushHeaders();

        return $this->withUnencryptedCookie('km_member', $this->uyeler[$ad][1]);
    }

    /**
     * Oda: bölge AB'de eczaneler A (ayse) ve B (mehmet), Z'de C; oda kurumunda ayse ve mehmet üye, baskan yönetici.
     *
     * @return array{a: int, b: int, c: int, oda: int}
     */
    private function hazirla(): array
    {
        $h = $this->admin();
        Uyeler::roller();
        $ab = $this->postJson(self::API.'/eski/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $z = $this->postJson(self::API.'/eski/bolgeler', ['body' => ['bolge_title' => 'Z']], $h)->json('id');
        $a = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'GÜNEŞ', 'bolge_id' => $ab]], $h)->json('id');
        $b = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'YONCA', 'bolge_id' => $ab]], $h)->json('id');
        $c = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'ZEYTİN', 'bolge_id' => $z]], $h)->json('id');
        foreach ([[$a, -3, $ab], [$a, 5, $ab], [$a, 40, $ab], [$b, 6, $ab], [$b, 40, $ab], [$b, 12, $ab], [$c, 7, $z]] as [$e, $fark, $bolge]) {
            DB::table('nobet_nobetler')->insert(['tarih' => $this->gun($fark), 'vardiya' => 'tumgun', 'bolge_id' => $bolge, 'eczane_id' => $e,
                'ozel_gun' => false, 'kaynak' => 'motor', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ayse = $this->uye('ayse', 'ayse@eczane.test');
        $mehmet = $this->uye('mehmet', 'mehmet@eczane.test');
        $baskan = $this->uye('baskan', 'baskan@oda.test');
        $this->uye('yabanci', 'yabanci@ornek.test');
        SettingsService::instance()->set(Organizations::SETTING, 'admins');
        $oda = (int) Organizations::create('Kadirli Eczacı Odası')['id'];
        SettingsService::instance()->set(NobetModule::KURUM, $oda);
        foreach ([[$ayse, 'member'], [$mehmet, 'member'], [$baskan, 'manager']] as [$uye, $rol]) {
            DB::table('member_org_members')->insert(['org_id' => $oda, 'member_id' => $uye, 'role' => $rol, 'title' => '', 'created_at' => Clock::now()]);
        }
        DB::table('nobet_eczaneler')->where('id', $a)->update(['uye_id' => $ayse]);
        DB::table('nobet_eczaneler')->where('id', $b)->update(['uye_id' => $mehmet]);

        return ['a' => $a, 'b' => $b, 'c' => $c, 'oda' => $oda];
    }

    /** @return list<string> */
    private function menu(string $ad, int $kurum = 0): array
    {
        return array_column($this->olarak($ad)->getJson('/v1/members/account?locale=tr'.($kurum ? "&org={$kurum}" : ''))->assertOk()->json('pages'), 'key');
    }

    private function nobetId(int $eczane, int $fark): int
    {
        return (int) DB::table('nobet_nobetler')->where('eczane_id', $eczane)->whereDate('tarih', $this->gun($fark))->value('id');
    }

    /** Üyenin oturum çerezi (km_member) yalnızca /api/v1/members yoluna gider: üye uçları orada olmalı (test istemcisi yola bakmaz). */
    public function test_uye_uclari_cerezin_yolunda(): void
    {
        $uclar = 0;
        foreach (app('router')->getRoutes() as $rota) {
            $denetleyici = explode('@', (string) $rota->getActionName())[0];
            if (in_array($denetleyici, [HesapController::class, OdaController::class], true)) {
                $this->assertStringStartsWith('v1/members/nobet', $rota->uri(), $rota->uri());
                $uclar++;
            }
        }
        $this->assertSame(11, $uclar);
    }

    public function test_menu_nobetlerim_ve_mazeret(): void
    {
        $k = $this->hazirla();

        $this->assertContains('nobet-nobetlerim', $this->menu('ayse'));
        $this->assertNotContains('nobet-oda', $this->menu('ayse', $k['oda']), 'eczacı oda yönetimini görmez');
        $this->assertNotContains('nobet-nobetlerim', $this->menu('yabanci'), 'odada olmayan, eczanesi olmayan üye');
        $this->assertContains('nobet-oda', $this->menu('baskan', $k['oda']));
        $this->olarak('ayse')->getJson('/v1/members/account/pages/nobet-nobetlerim?locale=tr')->assertOk()->assertJsonPath('key', 'nobet-nobetlerim')->assertSee('nobet-nobetlerim', false);
        $this->olarak('yabanci')->getJson('/v1/members/account/pages/nobet-nobetlerim?locale=tr')->assertNotFound();

        $ozet = $this->olarak('ayse')->getJson('/v1/members/nobet')->assertOk()->assertHeader('Cache-Control')->json();
        $this->assertSame(['GÜNEŞ', 2, 1, true], [$ozet['eczane']['ad'], count($ozet['nobetler']), $ozet['gecmis_nobet'], $ozet['takas_onayi']]);
        $this->flushHeaders();
        $this->withUnencryptedCookie('km_member', '');
        $this->getJson('/v1/members/nobet')->assertStatus(401);
        $this->olarak('yabanci')->getJson('/v1/members/nobet')->assertOk()->assertJson(['eczane' => null]);
        $this->olarak('yabanci')->postJson('/v1/members/nobet/mazeret', ['tarih' => $this->gun(5), 'aciklama' => 'Randevu'])->assertForbidden();

        // Mazeret: üye bildirir, odanın yöneticisi üyeye zil gider; yönetici üye karar verir, eczacıya döner.
        $id = $this->olarak('ayse')->postJson('/v1/members/nobet/mazeret', ['tarih' => $this->gun(5), 'aciklama' => 'Hastane randevusu'])->assertOk()->json('id');
        $this->assertSame([$this->uyeler['ayse'][0], 'talep'], [(int) DB::table('nobet_mazeretler')->where('id', $id)->value('uye_id'), DB::table('nobet_mazeretler')->where('id', $id)->value('durum')]);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['baskan'][0], 'type' => 'nobet.mazeret_talebi', 'url' => '/hesap/?sayfa=nobet-oda']);
        $this->assertDatabaseHas('notifications', ['user_id' => User::query()->where('role', 'admin')->value('id'), 'type' => 'nobet.mazeret_talebi']);

        $this->olarak('mehmet')->postJson("/v1/members/nobet/oda/mazeret/{$id}/karar", ['karar' => 'onay'])->assertForbidden();
        $this->olarak('mehmet')->deleteJson("/v1/members/nobet/mazeret/{$id}")->assertNotFound();
        $liste = $this->olarak('baskan')->getJson('/v1/members/nobet/oda')->assertOk()->json();
        $this->assertSame(['talep', 'GÜNEŞ'], [$liste['mazeretler'][0]['durum'], $liste['mazeretler'][0]['eczane']]);
        $this->olarak('baskan')->postJson("/v1/members/nobet/oda/mazeret/{$id}/karar", ['karar' => 'ret', 'not' => 'Yerinize bakacak yok'])->assertOk()->assertJsonPath('mazeretler.0.durum', 'ret');
        $this->assertSame($this->uyeler['baskan'][0], (int) DB::table('nobet_mazeretler')->where('id', $id)->value('karar_veren_uye_id'));
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['ayse'][0], 'type' => 'nobet.mazeret_karari', 'title' => 'Mazeret talebiniz reddedildi', 'url' => '/hesap/?sayfa=nobet-nobetlerim']);
        $this->olarak('ayse')->deleteJson("/v1/members/nobet/mazeret/{$id}")->assertStatus(409);
    }

    public function test_takas_teklif_kabul_ve_oda_onayi(): void
    {
        $k = $this->hazirla();
        $a5 = $this->nobetId($k['a'], 5);
        $b6 = $this->nobetId($k['b'], 6);

        // Adaylar: aynı bölge, başka gün; B'nin 40. gün nöbeti olmaz (A o gün zaten nöbetçi), başka bölgedeki C olmaz.
        $adaylar = $this->olarak('ayse')->getJson("/v1/members/nobet/takas-adaylari?nobet={$a5}")->assertOk()->json('adaylar');
        $this->assertSame([$this->gun(6), $this->gun(12)], array_column($adaylar, 'tarih'));
        $this->olarak('ayse')->getJson('/v1/members/nobet/takas-adaylari?nobet='.$b6)->assertStatus(422);
        $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $this->nobetId($k['c'], 7)])->assertStatus(422);
        $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $this->nobetId($k['a'], -3), 'karsi_nobet_id' => $b6])->assertStatus(422);

        $id = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $b6, 'aciklama' => 'Düğünüm var'])->assertOk()->json('id');
        $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $this->nobetId($k['b'], 12)])->assertStatus(409)->assertJsonPath('code', 'nobet_takas_var');
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['mehmet'][0], 'type' => 'nobet.takas_teklifi', 'title' => 'Nöbet takası teklifi: GÜNEŞ']);
        $gelen = $this->olarak('mehmet')->getJson('/v1/members/nobet')->json('takaslar.0');
        $this->assertSame(['gelen', 'teklif', 'Düğünüm var'], [$gelen['yon'], $gelen['durum'], $gelen['aciklama']]);

        // Teklif eden yanıt veremez; karşı taraf kabul eder; oda yönetimi onaylar.
        $this->olarak('ayse')->postJson("/v1/members/nobet/takas/{$id}/yanit", ['yanit' => 'kabul'])->assertNotFound();
        $this->olarak('baskan')->postJson("/v1/members/nobet/oda/takas/{$id}/karar", ['karar' => 'onay'])->assertStatus(409);
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$id}/yanit", ['yanit' => 'kabul'])->assertOk()->assertJson(['durum' => 'kabul']);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['baskan'][0], 'type' => 'nobet.takas_onayi']);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['ayse'][0], 'type' => 'nobet.takas_yaniti', 'title' => 'Takas teklifiniz kabul edildi']);
        $this->assertSame([$k['a'], $k['b']], [(int) DB::table('nobet_nobetler')->where('id', $a5)->value('eczane_id'), (int) DB::table('nobet_nobetler')->where('id', $b6)->value('eczane_id')], 'onaydan önce değişmez');

        $this->olarak('mehmet')->postJson("/v1/members/nobet/oda/takas/{$id}/karar", ['karar' => 'onay'])->assertForbidden();
        $this->olarak('baskan')->postJson("/v1/members/nobet/oda/takas/{$id}/karar", ['karar' => 'onay', 'not' => 'Uygun'])->assertOk();
        $this->assertSame([$k['b'], $k['a']], [(int) DB::table('nobet_nobetler')->where('id', $a5)->value('eczane_id'), (int) DB::table('nobet_nobetler')->where('id', $b6)->value('eczane_id')]);
        $this->assertSame(['elle', $id], [DB::table('nobet_nobetler')->where('id', $a5)->value('kaynak'), json_decode((string) DB::table('nobet_nobetler')->where('id', $a5)->value('gerekce'), true)['takas']]);
        foreach (['ayse', 'mehmet'] as $ad) {
            $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler[$ad][0], 'type' => 'nobet.takas_karari', 'title' => 'Nöbet takası onaylandı']);
        }
        $this->assertSame([$this->gun(6), $this->gun(40)], array_column($this->olarak('ayse')->getJson('/v1/members/nobet')->json('nobetler'), 'tarih'));
        $this->olarak('baskan')->postJson("/v1/members/nobet/oda/takas/{$id}/karar", ['karar' => 'onay'])->assertStatus(409);
    }

    public function test_takas_ret_iptal_gecersizlik_ve_onaysiz(): void
    {
        $k = $this->hazirla();
        $a5 = $this->nobetId($k['a'], 5);
        $b6 = $this->nobetId($k['b'], 6);
        $b12 = $this->nobetId($k['b'], 12);

        // Karşı eczane reddeder; teklif eden geri alır.
        $ret = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $b6])->json('id');
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$ret}/yanit", ['yanit' => 'belki'])->assertStatus(422);
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$ret}/yanit", ['yanit' => 'ret'])->assertOk()->assertJson(['durum' => 'ret']);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $this->uyeler['ayse'][0], 'title' => 'Takas teklifiniz reddedildi']);
        $this->olarak('ayse')->deleteJson("/v1/members/nobet/takas/{$ret}")->assertStatus(409);
        $iptal = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $b6])->json('id');
        $this->olarak('mehmet')->deleteJson("/v1/members/nobet/takas/{$iptal}")->assertNotFound();
        $this->olarak('ayse')->deleteJson("/v1/members/nobet/takas/{$iptal}")->assertOk();

        // Kabulden sonra nöbet yönetim arayüzünde başka eczaneye verildi: onay 409, talep iptal.
        $id = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $b12])->json('id');
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$id}/yanit", ['yanit' => 'kabul'])->assertOk();
        DB::table('nobet_nobetler')->where('id', $b12)->update(['eczane_id' => $k['c']]);
        $this->olarak('baskan')->postJson("/v1/members/nobet/oda/takas/{$id}/karar", ['karar' => 'onay'])->assertStatus(409)->assertJsonPath('code', 'nobet_takas_gecersiz');
        $this->assertSame('iptal', DB::table('nobet_takaslar')->where('id', $id)->value('durum'));
        DB::table('nobet_nobetler')->where('id', $b12)->update(['eczane_id' => $k['b']]);

        // Onay kapalıyken kabulde hemen uygulanır.
        SettingsService::instance()->set(NobetModule::TAKAS_ONAYI, false);
        $hemen = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $a5, 'karsi_nobet_id' => $b12])->json('id');
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$hemen}/yanit", ['yanit' => 'kabul'])->assertOk()->assertJson(['durum' => 'onay']);
        $this->assertSame($k['b'], (int) DB::table('nobet_nobetler')->where('id', $a5)->value('eczane_id'));
    }

    public function test_yonetim_arayuzunde_takas_karari(): void
    {
        $k = $this->hazirla();
        $h = $this->admin();
        $id = $this->olarak('ayse')->postJson('/v1/members/nobet/takas', ['nobet_id' => $this->nobetId($k['a'], 5), 'karsi_nobet_id' => $this->nobetId($k['b'], 6)])->json('id');
        $this->olarak('mehmet')->postJson("/v1/members/nobet/takas/{$id}/yanit", ['yanit' => 'kabul'])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => User::query()->where('role', 'admin')->value('id'), 'type' => 'nobet.takas_onayi']);

        $this->flushHeaders();
        $this->getJson(self::API.'/takaslar', $h)->assertOk()->assertJsonPath('takaslar.0.durum', 'kabul');
        $this->postJson(self::API."/takaslar/{$id}/karar", ['karar' => 'ret', 'not' => 'Bayram haftası'], $h)->assertOk()->assertJson(['karar' => 'ret']);
        $this->assertSame(['ret', 'yonetim'], [DB::table('nobet_takaslar')->where('id', $id)->value('durum'), DB::table('nobet_takaslar')->where('id', $id)->value('ret_eden')]);
        $this->assertDatabaseHas('events', ['event_type' => 'nobet.takas_karari']);
    }

    public function test_oda_yonetimi_eczane_hesap_bagi(): void
    {
        $k = $this->hazirla();
        $yabanci = $this->uyeler['yabanci'][0];
        $mehmet = $this->uyeler['mehmet'][0];

        $this->olarak('ayse')->getJson('/v1/members/nobet/oda')->assertForbidden();
        $veri = $this->olarak('baskan')->getJson('/v1/members/nobet/oda')->assertOk()->json();
        $this->assertSame(['GÜNEŞ', 'ayse'], [$veri['eczaneler'][0]['ad'], $veri['eczaneler'][0]['uye']]);
        $this->assertCount(3, $veri['uyeler']);

        $this->olarak('baskan')->putJson("/v1/members/nobet/oda/eczaneler/{$k['c']}/uye", ['uye_id' => $yabanci])->assertStatus(422);
        $this->olarak('baskan')->putJson("/v1/members/nobet/oda/eczaneler/{$k['c']}/uye", ['uye_id' => $mehmet])->assertStatus(409);
        $this->olarak('baskan')->putJson("/v1/members/nobet/oda/eczaneler/{$k['b']}/uye", ['uye_id' => null])->assertOk();
        $this->olarak('baskan')->putJson("/v1/members/nobet/oda/eczaneler/{$k['c']}/uye", ['uye_id' => $mehmet])->assertOk();
        $this->assertSame('ZEYTİN', $this->olarak('mehmet')->getJson('/v1/members/nobet')->json('eczane.ad'));

        // Oda kurumu silinince ayar sıfırlanır, sayfa kimseye görünmez.
        Organizations::delete($k['oda']);
        $this->assertSame(0, SettingsService::instance()->int(NobetModule::KURUM));
        $this->olarak('baskan')->getJson('/v1/members/nobet/oda')->assertForbidden();
    }

    public function test_panel_hesaplarindan_uyeye_gecis(): void
    {
        $h = $this->admin();
        Uyeler::roller();
        $bolge = $this->postJson(self::API.'/eski/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $a = $this->postJson(self::API.'/eski/eczaneler', ['body' => ['adi' => 'GÜNEŞ', 'bolge_id' => $bolge]], $h)->json('id');
        $this->postJson(self::API.'/eski/uyeler', ['body' => ['adi' => 'Ayşe Eczacı', 'eposta' => 'ayse@eczane.test', 'sifre' => 'Nobet-Sifresi-2026', 'rol' => 0, 'eczane_id' => $a]], $h)->assertOk();
        $this->postJson(self::API.'/eski/uyeler', ['body' => ['adi' => 'Oda Başkanı', 'eposta' => 'baskan@oda.test', 'sifre' => 'Nobet-Sifresi-2026', 'rol' => 1]], $h)->assertOk();
        $ec = $this->as(Uyeler::ECZACI, 'ayse');
        $mazeret = $this->postJson(self::API.'/benim/mazeret', ['tarih' => $this->gun(3), 'aciklama' => 'Randevu'], $ec)->assertOk()->json('id');

        $this->artisan('nobet:uyelere', ['--oda' => 'Kadirli Eczacı Odası', '--deneme' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('members')->count());

        $this->artisan('nobet:uyelere', ['--oda' => 'Kadirli Eczacı Odası', '--pasif' => true])->expectsOutputToContain('Kadirli Eczacı Odası')->assertExitCode(0);
        $oda = SettingsService::instance()->int(NobetModule::KURUM);
        $ayse = (int) DB::table('members')->where('email', 'ayse@eczane.test')->value('id');
        $baskan = (int) DB::table('members')->where('email', 'baskan@oda.test')->value('id');
        $this->assertSame(['admins', 'member', 'manager'], [SettingsService::instance()->string(Organizations::SETTING), Organizations::role($oda, $ayse), Organizations::role($oda, $baskan)]);
        $this->assertSame([$ayse, $ayse], [(int) DB::table('nobet_eczaneler')->where('id', $a)->value('uye_id'), (int) DB::table('nobet_mazeretler')->where('id', $mazeret)->value('uye_id')]);
        $this->assertSame(['passive', 'active'], [User::query()->where('email', 'ayse@eczane.test')->value('status'), User::query()->where('email', 'baskan@oda.test')->value('status')]);

        // Eczacı aynı şifreyle Hesabım'a girer, Nöbetlerim'de talebini görür; ikinci çalıştırma bir şey değiştirmez.
        $this->flushHeaders();
        $cerez = (string) $this->postJson('/v1/members/login', ['email' => 'ayse@eczane.test', 'password' => 'Nobet-Sifresi-2026'])->assertOk()->getCookie('km_member', false)?->getValue();
        $this->withUnencryptedCookie('km_member', $cerez)->getJson('/v1/members/nobet')->assertOk()->assertJsonPath('eczane.ad', 'GÜNEŞ')->assertJsonPath('mazeretler.0.id', $mazeret);
        $this->artisan('nobet:uyelere')->assertExitCode(0);
        $this->assertSame([2, 1], [DB::table('members')->count(), DB::table('member_orgs')->count()]);
    }
}
