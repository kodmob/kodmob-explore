<?php

namespace KodmobTools\Nobet\Tests;

use App\Core\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\NobetModule;
use KodmobTools\Nobet\Veri\Nobetler;
use Tests\TestCase;

/** Yönetim arayüzünün uyum uçları: eski biçim, izin listesi, kısmi güncelleme, sayaçtan devir, özel gün kurası, yetki. */
class UyumTest extends TestCase
{
    private const API = '/v1/admin/nobet/eski';

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_bolgeler')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::instance()->set(NobetModule::ILCE_KEY, 1423);
        SettingsService::instance()->set(NobetModule::SEHIR_KEY, 80);
    }

    public function test_bolge_ve_grup_eski_bicimde_eklenir_okunur_guncellenir(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB', 'ilce_id' => 1423]], $h)->assertOk()->json('id');
        $this->getJson(self::API.'/bolgeler?sutun=ilce_id&deger=1423', $h)->assertOk()->assertExactJson([['id' => $ab, 'bolge_title' => 'AB', 'ilce_id' => 1423]]);
        $this->getJson(self::API.'/bolgeler?sutun=ilce_id&deger=80', $h)->assertOk()->assertExactJson([]);

        $g1 = $this->postJson(self::API.'/gruplar', ['body' => ['grup_title' => '11', 'bolge_id' => $ab]], $h)->assertOk()->json('id');
        $g2 = $this->postJson(self::API.'/gruplar', ['body' => ['grup_title' => '12', 'bolge_id' => $ab]], $h)->assertOk()->json('id');
        // Kısmi güncelleme: yalnızca eş gruplar; ad ve bölge korunur.
        $this->putJson(self::API."/gruplar/{$g1}", ['body' => ['grup_eslesme_ids' => (string) $g2, 'grup_yedek_id' => $g2]], $h)->assertOk();
        $this->getJson(self::API."/gruplar/{$g1}", $h)->assertOk()
            ->assertJson(['grup_title' => '11', 'grup_eslesme_ids' => (string) $g2, 'grup_yedek_id' => $g2, 'bolge_id' => $ab, 'ilce_id' => 1423]);

        $this->putJson(self::API."/bolgeler/{$ab}", ['body' => ['bolge_title' => '']], $h)->assertStatus(422)->assertJsonPath('details.fields.ad', 'Bölgenin adını yazın.');
    }

    public function test_eczane_eski_alanlarla_kaydedilir_sayaclar_hesaplanir_devir_olur(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $id = $this->postJson(self::API.'/eczaneler', ['body' => [
            'adi' => 'GÜNEŞ', 'tel' => '3125550101', 'sehir' => 'OSMANİYE', 'ilce' => 'KADİRLİ', 'mahalle' => 'DERE', 'cadde' => 'ANDIRIN', 'binano' => '115/A',
            'bolge_id' => (string) $ab, 'grup_id' => '0', 'durum' => '1', 'renk' => '#06a560', 'latlong' => '37.1234, 36.5678',
            'cumartesiSayisi' => 3, 'pazarSayisi' => '',
        ]], $h)->assertOk()->json('id');

        $e = $this->getJson(self::API."/eczaneler/{$id}", $h)->assertOk()->json();
        $this->assertSame(['GÜNEŞ', '0312 555 01 01', 'DERE ANDIRIN 115/A', '0', '1', '#06A560', '37.1234, 36.5678', 3, 0],
            [$e['adi'], $e['tel'], $e['adres'], $e['grup_id'], $e['durum'], $e['renk'], $e['latlong'], $e['cumartesiSayisi'], $e['pazarSayisi']]);

        // Bir cumartesi nöbeti yazılınca sayaç 4 (3 devir + 1); arayüz 5 yazarsa devir 4 olur (5 − 1 sayılan).
        DB::table('nobet_nobetler')->insert(['tarih' => '2027-01-02', 'vardiya' => 'tumgun', 'bolge_id' => $ab, 'eczane_id' => $id, 'ozel_gun' => false,
            'kaynak' => 'elle', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(4, $this->getJson(self::API."/eczaneler/{$id}", $h)->json('cumartesiSayisi'));
        $this->putJson(self::API."/eczaneler/{$id}", ['body' => ['cumartesiSayisi' => 5, 'durum' => '0']], $h)->assertOk();
        $e = $this->getJson(self::API."/eczaneler/{$id}", $h)->json();
        $this->assertSame([5, '0', 'DERE', 'GÜNEŞ'], [$e['cumartesiSayisi'], $e['durum'], $e['mahalle'], $e['adi']]);
        $this->assertSame(['cumartesi' => 4], json_decode(DB::table('nobet_eczaneler')->where('id', $id)->value('devir'), true));

        $this->getJson(self::API.'/raporlar?baslangic=2027-01-01&bitis=2027-01-31', $h)->assertOk()
            ->assertJsonPath('0.gun_adi', 'cumartesi')->assertJsonPath('0.ay_adi', 'ocak')->assertJsonPath('0.sezon_adi', 'kis')
            ->assertJsonPath('0.gun_tipi', 'haftasonu')->assertJsonPath('0.tarih', '2027-01-02 00:00:00');
    }

    public function test_ozel_gun_kurasi_eczane_ids_ile_yazilir(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $e1 = $this->postJson(self::API.'/eczaneler', ['body' => ['adi' => 'A', 'bolge_id' => $ab]], $h)->json('id');
        $e2 = $this->postJson(self::API.'/eczaneler', ['body' => ['adi' => 'B', 'bolge_id' => $ab]], $h)->json('id');

        $g = $this->postJson(self::API.'/ozelgunler', ['body' => ['adi' => 'Bayram', 'tarih' => '2027-03-20 00:00:00', 'eczane_ids' => "{$e1},{$e2}"]], $h)->assertOk()->json('id');
        $this->getJson(self::API."/ozelgunler/{$g}", $h)->assertJson(['adi' => 'Bayram', 'eczane_ids' => "{$e1},{$e2}", 'tarih' => '2027-03-20 00:00:00']);
        $this->putJson(self::API."/ozelgunler/{$g}", ['body' => ['eczane_ids' => (string) $e2]], $h)->assertOk();
        $this->assertSame([$e2], DB::table('nobet_nobetler')->where('tarih', '2027-03-20')->where('ozel_gun', true)->pluck('eczane_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_mahalle_ve_cadde_eklenir_okunur(): void
    {
        $h = $this->admin();
        $this->postJson(self::API.'/mahalle', ['body' => ['mahalle_title' => 'DERE', 'mahalle_key' => 97324, 'mahalle_ilcekey' => 1423]], $h)->assertOk();
        $this->postJson(self::API.'/mahalle', ['body' => ['mahalle_title' => 'DERE']], $h)->assertStatus(422)->assertJsonPath('details.fields.ad', 'Bu mahalle zaten var.');
        $yeni = $this->postJson(self::API.'/mahalle', ['body' => ['mahalle_title' => 'YENİ', 'mahalle_key' => 97324]], $h)->assertOk()->json('id');
        $this->assertSame(900000, (int) DB::table('nobet_mahalleler')->where('id', $yeni)->value('anahtar'), 'çakışan anahtar yerine yenisi');

        $this->getJson(self::API.'/mahalle?sutun=mahalle_ilcekey&deger=1423', $h)->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.mahalle_title', 'DERE')->assertJsonPath('0.mahalle_key', 97324)->assertJsonPath('0.mahalle_sehirkey', 80);

        $this->postJson(self::API.'/sokak_cadde', ['body' => ['sokak_cadde_title' => 'ANDIRIN', 'sokak_cadde_mahallekey' => 97324]], $h)->assertOk();
        $this->postJson(self::API.'/sokak_cadde', ['body' => ['sokak_cadde_title' => 'X', 'sokak_cadde_mahallekey' => 5]], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.sokak_cadde_mahallekey', 'Mahalle seçin.');
        $cadde = $this->getJson(self::API.'/sokak_cadde?sutun=sokak_cadde_mahallekey&deger=97324', $h)->assertOk()->json('0');
        $this->assertSame(['ANDIRIN', $cadde['sokak_cadde_id'], 1423], [$cadde['sokak_cadde_title'], $cadde['sokak_cadde_key'], $cadde['sokak_cadde_ilcekey']]);
    }

    public function test_nobet_kaydi_eklenir_degistirilir_silinir_ozel_gun_tarihten_gelir(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $dogu = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'Doğu']], $h)->json('id');
        $e1 = $this->postJson(self::API.'/eczaneler', ['body' => ['adi' => 'A', 'bolge_id' => $ab]], $h)->json('id');
        $e2 = $this->postJson(self::API.'/eczaneler', ['body' => ['adi' => 'B', 'bolge_id' => $dogu]], $h)->json('id');
        $this->postJson(self::API.'/ozelgunler', ['body' => ['adi' => 'Bayram', 'tarih' => '2027-03-20']], $h)->assertOk();

        // İstemcinin gönderdiği ozel_gun / bölge yok sayılır: özel gün tarihten, bölge eczaneden.
        $n1 = $this->postJson(self::API.'/raporlar', ['body' => ['eczane_id' => $e1, 'tarih' => '2027-03-20', 'ozel_gun' => 0, 'bolge_id' => 0, 'gecmis_gun' => 1]], $h)->assertOk()->json('id');
        $n2 = $this->postJson(self::API.'/raporlar', ['body' => ['eczane_id' => $e1, 'tarih' => '2027-03-21 00:00:00']], $h)->assertOk()->json('id');
        $this->postJson(self::API.'/raporlar', ['body' => ['eczane_id' => $e1, 'tarih' => '2027-03-21']], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.eczane_id', 'Bu eczane o gün zaten nöbetçi.');
        $satir = fn (int $id) => DB::table('nobet_nobetler')->where('id', $id)->first();
        $this->assertSame([1, $ab, 'elle'], [(int) $satir($n1)->ozel_gun, (int) $satir($n1)->bolge_id, $satir($n1)->kaynak]);
        $this->assertSame(0, (int) $satir($n2)->ozel_gun);

        // Başka bölgeden eczaneye verilir: bölge yeni eczanenin; gerekçede önceki eczane.
        $this->putJson(self::API."/raporlar/{$n2}", ['body' => ['eczane_id' => $e2, 'ilce_id' => 1423, 'bolge_id' => 999]], $h)->assertOk();
        $this->assertSame([$e2, $dogu, ['elle' => true, 'onceki' => $e1]], [(int) $satir($n2)->eczane_id, (int) $satir($n2)->bolge_id, json_decode($satir($n2)->gerekce, true)]);

        // Eski ekranların hatalı çağrıları (yalnızca ozel_gun ya da sayaç gönderen) hiçbir şeyi değiştirmez.
        $this->putJson(self::API."/raporlar/{$n2}", ['body' => ['ozel_gun' => 1, 'cumartesiSayisi' => 9]], $h)->assertOk();
        $this->assertSame([0, '2027-03-21'], [(int) $satir($n2)->ozel_gun, substr($satir($n2)->tarih, 0, 10)]);
        // Tarih süzgeci gün düzeyinde (ekranlar saatli ve saatsiz gönderir).
        $this->getJson(self::API.'/raporlar?sutun=tarih&deger=2027-03-21', $h)->assertJsonCount(1);
        $this->getJson(self::API.'/raporlar?sutun=tarih&deger='.urlencode('2027-03-21 00:00:00'), $h)->assertJsonCount(1);

        // Özel gün eklenince / silinince o günün nöbetleri işaretlenir / işareti kalkar.
        $g = $this->postJson(self::API.'/ozelgunler', ['body' => ['adi' => 'Arife', 'tarih' => '2027-03-21']], $h)->json('id');
        $this->assertSame(1, (int) $satir($n2)->ozel_gun);
        $this->deleteJson(self::API."/ozelgunler/{$g}", [], $h)->assertOk();
        $this->assertSame(0, (int) $satir($n2)->ozel_gun);

        $this->deleteJson(self::API."/raporlar/{$n1}", [], $h)->assertOk();
        $this->assertNull($satir($n1));
    }

    public function test_mazeret_eski_alanlarla_eklenir_gunune_gore_bulunur_guncellenir_silinir(): void
    {
        $h = $this->admin();
        // Nöbet Kayıtları ekranının gönderdiği biçim.
        $id = $this->postJson(self::API.'/mazeretler', ['body' => ['aciklama' => 'Eczacı hasta', 'turu' => 'mazeret', 'ilce_id' => 1423, 'gunu' => '2027-01-05']], $h)->assertOk()->json('id');
        $this->getJson(self::API.'/mazeretler?sutun=gunu&deger=2027-01-05', $h)->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.aciklama', 'Eczacı hasta')->assertJsonPath('0.turu', 'mazeret')->assertJsonPath('0.gunu', '2027-01-05 00:00:00');
        $this->putJson(self::API."/mazeretler/{$id}", ['body' => ['aciklama' => 'Eczacı hasta, rapor var']], $h)->assertOk();
        $this->assertSame(['Eczacı hasta, rapor var', '2027-01-05'],
            [DB::table('nobet_mazeretler')->where('id', $id)->value('aciklama'), substr((string) DB::table('nobet_mazeretler')->where('id', $id)->value('tarih'), 0, 10)]);
        $this->postJson(self::API.'/mazeretler', ['body' => ['aciklama' => '', 'gunu' => 'x']], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.aciklama', 'Mazeretin açıklamasını yazın.')->assertJsonPath('details.fields.tarih', 'Geçerli bir tarih seçin.');
        $this->deleteJson(self::API."/mazeretler/{$id}", [], $h)->assertOk();
        $this->assertSame(0, DB::table('nobet_mazeretler')->count());
    }

    public function test_adres_tarifi_kaydedilir_korunur_sitede_gorunur(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $id = $this->postJson(self::API.'/eczaneler', ['body' => ['adi' => 'GÜNEŞ', 'bolge_id' => (string) $ab, 'tarif' => 'Devlet Hastanesi karşısı']], $h)->assertOk()->json('id');
        $this->getJson(self::API."/eczaneler/{$id}", $h)->assertJsonPath('tarif', 'Devlet Hastanesi karşısı');

        // Panelin formu tarifi göndermezse korunur; boş gönderilirse silinir.
        $this->putJson("/v1/admin/nobet/eczaneler/{$id}", ['ad' => 'GÜNEŞ', 'bolge_id' => $ab, 'telefon' => '0312 555 01 01'], $h)->assertOk()->assertJsonPath('tarif', 'Devlet Hastanesi karşısı');
        DB::table('nobet_nobetler')->insert(['tarih' => '2027-01-05', 'vardiya' => 'tumgun', 'bolge_id' => $ab, 'eczane_id' => $id, 'ozel_gun' => false, 'kaynak' => 'elle', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('Devlet Hastanesi karşısı', (new Nobetler)->nobetciler(1, '08:30', new \DateTimeImmutable('2027-01-05 12:00'))['gunler'][0]['nobetler'][0]['tarif']);

        $this->putJson(self::API."/eczaneler/{$id}", ['body' => ['tarif' => '']], $h)->assertOk();
        $this->getJson(self::API."/eczaneler/{$id}", $h)->assertJsonPath('tarif', null)->assertJsonPath('adi', 'GÜNEŞ');
        $this->putJson(self::API."/eczaneler/{$id}", ['body' => ['tarif' => str_repeat('x', 301)]], $h)->assertStatus(422)->assertJsonPath('details.fields.tarif', 'Adres tarifi en çok 300 karakter olabilir.');
    }

    public function test_excelden_toplu_ekleme_hepsi_ya_da_hicbiri(): void
    {
        $h = $this->admin();
        $ab = $this->postJson(self::API.'/bolgeler', ['body' => ['bolge_title' => 'AB']], $h)->json('id');
        $grup = $this->postJson(self::API.'/gruplar', ['body' => ['grup_title' => 'Merkez 1', 'bolge_id' => $ab]], $h)->json('id');

        // Eczaneler: bölge / grup kimlikle ya da adıyla.
        $this->postJson(self::API.'/eczaneler/toplu', ['rows' => [
            ['_satir' => 2, 'adi' => 'GÜNEŞ', 'bolge_id' => (string) $ab, 'grup_id' => (string) $grup],
            ['_satir' => 3, 'adi' => 'YONCA', 'bolge_id' => 'ab', 'grup_id' => 'merkez 1'],
        ]], $h)->assertOk()->assertJson(['eklenen' => 2]);
        $eczaneler = DB::table('nobet_eczaneler')->orderBy('id')->get(['id', 'ad', 'bolge_id', 'grup_id']);
        $this->assertSame([[$ab, $grup], [$ab, $grup]], $eczaneler->map(fn ($e) => [(int) $e->bolge_id, (int) $e->grup_id])->all());
        [$a, $b] = $eczaneler->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Nöbetler: bir satır bile hatalıysa hiçbiri yazılmaz, hatalar Excel satır numarasıyla.
        $hatali = $this->postJson(self::API.'/raporlar/toplu', ['rows' => [
            ['_satir' => 2, 'tarih' => '2027-02-01', 'eczane_id' => $a],
            ['_satir' => 3, 'tarih' => '2027-02-01', 'eczane_id' => $a],
            ['_satir' => 4, 'tarih' => '2027-02-31', 'eczane_id' => $b],
        ]], $h)->assertStatus(422);
        $hatali->assertJsonPath('message', '2 satırda hata var; hiçbir satır eklenmedi.')
            ->assertJsonPath('details.fields.rows', "Satır 3: Bu eczane o gün zaten nöbetçi.\nSatır 4: Geçerli bir tarih seçin.");
        $this->assertSame(0, DB::table('nobet_nobetler')->count());

        $this->postJson(self::API.'/raporlar/toplu', ['rows' => [
            ['tarih' => '2027-02-01', 'eczane_id' => $a, 'nobet_tipi' => 'tumgun', 'gun_adi' => 'yok sayılır'],
            ['tarih' => '2027-02-02', 'eczane_id' => $b, 'nobet_tipi' => 'gece'],
        ]], $h)->assertOk()->assertJson(['eklenen' => 2]);
        $this->assertSame(['tumgun', 'gece'], DB::table('nobet_nobetler')->orderBy('tarih')->pluck('vardiya')->all());

        // Başka tablo toplu eklenemez; yetkisiz olmaz.
        $this->postJson(self::API.'/bolgeler/toplu', ['rows' => [['bolge_title' => 'X']]], $h)->assertNotFound();
        $this->postJson(self::API.'/raporlar/toplu', ['rows' => []], $h)->assertStatus(422)->assertJsonPath('details.fields.rows', 'Eklenecek satır yok.');
        $this->postJson(self::API.'/raporlar/toplu', ['rows' => [['tarih' => '2027-03-01', 'eczane_id' => $a]]], $this->as('editor'))->assertForbidden();
    }

    public function test_izin_listesi_disi_ve_yetkisiz_istekler_reddedilir(): void
    {
        $h = $this->admin();
        $this->getJson(self::API.'/ayarlar', $h)->assertNotFound();
        $this->getJson(self::API.'/bolgeler?sutun=sifre&deger=x', $h)->assertStatus(422);
        // Okunur ama yazılamaz tablo: yazma rotası yok (405).
        $this->postJson(self::API.'/sehir', ['body' => ['sehir_title' => 'X']], $h)->assertStatus(405);
        $this->postJson(self::API.'/donguler', ['body' => ['adi' => 'x']], $h)->assertStatus(403)->assertJsonPath('code', 'nobet_salt_okunur');
        $this->getJson(self::API.'/bolgeler')->assertUnauthorized();
        $this->getJson(self::API.'/bolgeler', $this->as('editor'))->assertForbidden();

        $this->getJson(self::API.'/sehir?sutun=sehir_key&deger=80', $h)->assertOk()->assertJsonPath('0.sehir_title', 'OSMANİYE');
        $this->getJson('/v1/nobet/oda')->assertOk()->assertJson(['sehir_key' => 80, 'ilce_key' => 1423, 'sehir' => 'OSMANİYE', 'ilce' => 'KADİRLİ']);
    }
}
