<?php

namespace KodmobTools\Nobet\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\Veri\Nobetler;
use Tests\TestCase;

/** Nöbet'in panel uçları: yetki, kayıtlar ve doğrulamaları, döngü (önizleme, kaydetme, atlanan günler, geçmiş), takvim, elle değiştirme. */
class NobetTest extends TestCase
{
    /**
     * Laravel bellek içi veritabanını süreç başına bir kez migrate edip bağlantıyı yeniden kullanır; tam takımda ilk
     * testler çatınınkiler (paketler yüklenmeden) olduğundan paketin tabloları kurulmamış olabilir. Test işlemi
     * içinde kurulur, test bitince geri alınır.
     */
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_bolgeler')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    private const API = '/v1/admin/nobet';

    /** İki bölge (Merkez 3, Doğu 2 eczane). @return array{h: array, merkez: int, dogu: int, eczaneler: list<int>} */
    private function kur(): array
    {
        $h = $this->admin();
        $merkez = $this->postJson(self::API.'/bolgeler', ['ad' => 'Merkez', 'sira' => 1], $h)->assertOk()->json('id');
        $dogu = $this->postJson(self::API.'/bolgeler', ['ad' => 'Doğu', 'sira' => 2], $h)->assertOk()->json('id');
        $eczaneler = [];
        foreach ([[$merkez, 'Lale'], [$merkez, 'Papatya'], [$merkez, 'Menekşe'], [$dogu, 'Şifa'], [$dogu, 'Hayat']] as [$bolge, $ad]) {
            $eczaneler[] = $this->postJson(self::API.'/eczaneler', ['ad' => "{$ad} Eczanesi", 'bolge_id' => $bolge, 'telefon' => '0328 111 22 33'], $h)
                ->assertOk()->json('id');
        }

        return ['h' => $h, 'merkez' => $merkez, 'dogu' => $dogu, 'eczaneler' => $eczaneler];
    }

    public function test_okuma_ve_yazma_yetkiye_bagli(): void
    {
        $this->getJson(self::API.'/kayitlar', $this->admin())->assertOk()->assertJsonPath('kurallar.0.harf', 'A');
        $this->getJson(self::API.'/kayitlar', $this->as('editor'))->assertForbidden();
        $this->postJson(self::API.'/bolgeler', ['ad' => 'Merkez'], $this->as('editor'))->assertForbidden();
    }

    public function test_kayitlarin_dogrulamalari(): void
    {
        $k = $this->kur();
        $h = $k['h'];

        $this->postJson(self::API.'/bolgeler', ['ad' => 'Merkez'], $h)->assertStatus(422)->assertJsonPath('details.fields.ad', 'Bu adla bir bölge var.');
        $this->deleteJson(self::API.'/bolgeler/'.$k['merkez'], [], $h)->assertStatus(422)->assertJsonPath('code', 'nobet_bolge_dolu');

        $this->postJson(self::API.'/eczaneler', ['ad' => 'X', 'bolge_id' => 999], $h)->assertStatus(422)->assertJsonPath('details.fields.bolge_id', 'Bölge seçin.');
        $this->postJson(self::API.'/eczaneler', ['ad' => 'X', 'bolge_id' => $k['merkez'], 'enlem' => '37.07'], $h)
            ->assertStatus(422)->assertJsonPath('details.fields.boylam', 'Konum için enlem ve boylamın ikisi de gerekli.');
        $this->postJson(self::API.'/eczaneler', ['ad' => 'X', 'bolge_id' => $k['merkez'], 'devir' => ['cumartesi' => -1]], $h)
            ->assertStatus(422)->assertJsonPath('details.fields.devir', 'Devir sayıları 0 ile 9999 arasında tam sayı olmalı.');
        $yeni = $this->postJson(self::API.'/eczaneler', ['ad' => 'Yeni', 'bolge_id' => $k['merkez'], 'grup_id' => '', 'enlem' => '37.0755', 'boylam' => '36.2285',
            'devir' => ['cumartesi' => 4, 'pazar' => 0]], $h)->assertOk();
        $yeni->assertJsonPath('devir', ['cumartesi' => 4])->assertJsonPath('grup_id', null)->assertJsonPath('enlem', 37.0755);

        $g1 = $this->postJson(self::API.'/gruplar', ['ad' => 'A grubu'], $h)->assertOk()->json('id');
        $this->postJson(self::API.'/gruplar', ['ad' => 'B grubu', 'eslesme' => [$g1, 999]], $h)->assertStatus(422)->assertJsonPath('details.fields.eslesme', 'Eş gruplar var olan başka gruplar olmalı.');
        $g2 = $this->postJson(self::API.'/gruplar', ['ad' => 'B grubu', 'eslesme' => [$g1], 'yedek_id' => $g1], $h)->assertOk()->json('id');
        $this->deleteJson(self::API.'/gruplar/'.$g1, [], $h)->assertOk();
        $this->assertSame(['[]', null], [DB::table('nobet_gruplar')->where('id', $g2)->value('eslesme'), DB::table('nobet_gruplar')->where('id', $g2)->value('yedek_id')]);

        $this->postJson(self::API.'/ozel-gunler', ['tarih' => '2026-02-30', 'ad' => 'Yok'], $h)->assertStatus(422)->assertJsonPath('details.fields.tarih', 'Geçerli bir tarih seçin.');
        $this->postJson(self::API.'/ozel-gunler', ['tarih' => '2027-01-01', 'ad' => 'Yılbaşı'], $h)->assertOk();
        $this->postJson(self::API.'/ozel-gunler', ['tarih' => '2027-01-01', 'ad' => 'Tekrar'], $h)->assertStatus(422);
    }

    public function test_onizleme_yazmaz_kaydetme_her_gun_her_bolgeye_nobetci_yazar(): void
    {
        $k = $this->kur();
        $girdi = ['baslangic' => '2027-01-04', 'gun' => 14, 'kurallar' => 'A,N,B,C,D,E', 'tohum' => 5];

        $onizleme = $this->postJson(self::API.'/donguler/onizle', $girdi, $k['h'])->assertOk();
        $onizleme->assertJsonPath('atama_sayisi', 28)->assertJsonPath('dongu', null)->assertJsonCount(28, 'atamalar');
        $this->assertSame(0, DB::table('nobet_nobetler')->count());

        $kayit = $this->postJson(self::API.'/donguler', $girdi, $k['h'])->assertOk();
        $kayit->assertJsonPath('dongu.baslangic', '2027-01-04')->assertJsonPath('dongu.bitis', '2027-01-17')->assertJsonPath('dongu.kurallar', 'A,N,B,C,D,E');
        $this->assertSame(28, DB::table('nobet_nobetler')->count());
        $this->assertSame(
            array_map(fn ($a) => [$a['tarih'], $a['bolge'], $a['eczane']], $onizleme->json('atamalar')),
            DB::table('nobet_nobetler')->orderBy('id')->get()->map(fn ($n) => [substr($n->tarih, 0, 10), (int) $n->bolge_id, (int) $n->eczane_id])->all(),
            'aynı tohum, önizlenen takvim kaydedilir'
        );

        $takvim = $this->getJson(self::API.'/takvim?baslangic=2027-01-04&bitis=2027-01-04', $k['h'])->assertOk();
        $takvim->assertJsonCount(2, 'nobetler')->assertJsonPath('bolgeler.0.ad', 'Merkez')->assertJsonPath('nobetler.0.kaynak', Nobetler::MOTOR);
        $this->assertArrayHasKey('adimlar', $takvim->json('nobetler.0.gerekce'));
    }

    public function test_cakisan_dongu_dolu_gunleri_atlar_ve_gecmisi_sayar(): void
    {
        $k = $this->kur();
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-04', 'gun' => 7, 'kurallar' => 'B'], $k['h'])->assertOk();

        $ikinci = $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-08', 'gun' => 7, 'kurallar' => 'B'], $k['h'])->assertOk();
        $ikinci->assertJsonPath('atlanan_gunler', ['2027-01-08', '2027-01-09', '2027-01-10'])->assertJsonPath('atama_sayisi', 8);
        $this->assertSame(1, DB::table('nobet_nobetler')->where('tarih', '2027-01-09')->where('bolge_id', $k['merkez'])->count());
    }

    public function test_r_s_ve_gecmis_sayac_baslangici(): void
    {
        $k = $this->kur();
        [$topal, $yuksek, $caglar] = $k['eczaneler'];
        // Eski nöbetler: Lale ve Papatya 2026'da cumartesi tuttu; Menekşe 2027-01-01'de (cuma).
        foreach ([[$topal, '2026-06-06'], [$yuksek, '2026-06-13'], [$caglar, '2027-01-01']] as [$e, $t]) {
            DB::table('nobet_nobetler')->insert(['tarih' => $t, 'vardiya' => 'tumgun', 'bolge_id' => $k['merkez'], 'eczane_id' => $e,
                'ozel_gun' => false, 'kaynak' => 'elle', 'created_at' => now(), 'updated_at' => now()]);
        }
        $merkez = fn ($yanit) => collect($yanit->json('atamalar'))->firstWhere('bolge', $k['merkez'])['eczane'];
        $girdi = ['baslangic' => '2027-01-02', 'gun' => 1];

        // B: cumartesi tutmamış tek eczane Menekşe.
        $this->assertSame($caglar, $merkez($this->postJson(self::API.'/donguler/onizle', $girdi + ['kurallar' => 'B'], $k['h'])->assertOk()));
        // Geçmiş 2027'den sayılırsa üçü de eşit; R (Menekşe dün nöbetçiydi) ve S (son nöbeti en eski Lale) seçer.
        $this->assertSame($topal, $merkez($this->postJson(self::API.'/donguler/onizle', $girdi + ['kurallar' => 'B,R,S', 'sayac_baslangic' => '2027-01-01'], $k['h'])->assertOk()));
        $kayit = $this->postJson(self::API.'/donguler', $girdi + ['kurallar' => 'R,S', 'en_az_aralik' => 3, 'sayac_baslangic' => '2027-01-01'], $k['h'])->assertOk();
        $kayit->assertJsonPath('dongu.kurallar', 'R,S')->assertJsonPath('dongu.ayarlar.en_az_aralik', 3)->assertJsonPath('dongu.ayarlar.sayac_baslangic', '2027-01-01');

        $this->postJson(self::API.'/donguler/onizle', ['baslangic' => '2027-02-01', 'gun' => 1, 'en_az_aralik' => 0, 'sayac_baslangic' => '2027-03-01'], $k['h'])
            ->assertStatus(422)
            ->assertJsonPath('details.fields.en_az_aralik', 'İki nöbet arası en az gün 1 ile 60 arasında olmalı.')
            ->assertJsonPath('details.fields.sayac_baslangic', 'Geçmiş raporların başlangıcı döngünün başlangıcından sonra olamaz.');
    }

    public function test_alt_grup_secimde_ust_grubuyla_ayni_sayilir(): void
    {
        $k = $this->kur();
        $h = $k['h'];
        [$topal, $yuksek, $caglar, $sifa, $hayat] = $k['eczaneler'];
        $a = $this->postJson(self::API.'/gruplar', ['ad' => 'A'], $h)->assertOk()->json('id');
        $b = $this->postJson(self::API.'/gruplar', ['ad' => 'B'], $h)->assertOk()->json('id');
        $a1 = $this->postJson(self::API.'/gruplar', ['ad' => 'A1', 'ust_id' => $a], $h)->assertOk()->assertJsonPath('ust_id', $a)->json('id');

        // Tek düzey: alt grubun altına, kendi altına, alt grubu olan grup başkasının altına alınamaz.
        $this->postJson(self::API.'/gruplar', ['ad' => 'A2', 'ust_id' => $a1], $h)->assertStatus(422)->assertJsonPath('details.fields.ust_id', 'Üst grup kendisi bir alt grup olamaz (tek düzey).');
        $this->putJson(self::API.'/gruplar/'.$a, ['ad' => 'A', 'ust_id' => $a], $h)->assertStatus(422)->assertJsonPath('details.fields.ust_id', 'Üst grup var olan başka bir grup olmalı.');
        $this->putJson(self::API.'/gruplar/'.$a, ['ad' => 'A', 'ust_id' => $b], $h)->assertStatus(422)->assertJsonPath('details.fields.ust_id', 'Bu grubun alt grupları var; başka bir grubun altına alınamaz.');

        // Merkez'in üç eczanesi alt grup A1'de, Doğu'da Şifa B'de. I: A ile B aynı gün nöbetçi olmaz → A1 de B ile olmaz.
        foreach ([$topal, $yuksek, $caglar] as $e) {
            DB::table('nobet_eczaneler')->where('id', $e)->update(['grup_id' => $a1]);
        }
        DB::table('nobet_eczaneler')->where('id', $sifa)->update(['grup_id' => $b]);
        $girdi = ['baslangic' => '2027-01-04', 'gun' => 6, 'kurallar' => 'I,A', 'grup_engelleri' => [[$a, $b]], 'tohum' => 3];
        $dogu = $this->postJson(self::API.'/donguler', $girdi, $h)->assertOk();
        $this->assertSame([$hayat], DB::table('nobet_nobetler')->where('bolge_id', $k['dogu'])->pluck('eczane_id')->map(fn ($e) => (int) $e)->unique()->values()->all());
        // Kayıtta eczanenin kendi grubu (A1) yazılır; döngünün ayarlarında girilen çift olduğu gibi.
        $this->assertSame([$a1], DB::table('nobet_nobetler')->where('bolge_id', $k['merkez'])->pluck('grup_id')->map(fn ($g) => (int) $g)->unique()->values()->all());
        $dogu->assertJsonPath('dongu.ayarlar.grup_engelleri', [[$a, $b]]);

        // Üst grup silinince alt grubu ana grup olur.
        $this->deleteJson(self::API.'/gruplar/'.$a, [], $h)->assertOk();
        $this->assertNull(DB::table('nobet_gruplar')->where('id', $a1)->value('ust_id'));
    }

    public function test_ozel_gun_ve_kural_o(): void
    {
        $k = $this->kur();
        $this->postJson(self::API.'/ozel-gunler', ['tarih' => '2027-01-01', 'ad' => 'Yılbaşı'], $k['h'])->assertOk();

        $this->postJson(self::API.'/donguler/onizle', ['baslangic' => '2027-01-01', 'gun' => 2, 'kurallar' => 'O'], $k['h'])->assertJsonPath('atama_sayisi', 2);
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-01', 'gun' => 2, 'kurallar' => ''], $k['h'])->assertOk();
        $this->assertSame([1, 1], [
            (int) DB::table('nobet_nobetler')->where('tarih', '2027-01-01')->where('bolge_id', $k['merkez'])->value('ozel_gun'),
            DB::table('nobet_nobetler')->where('tarih', '2027-01-02')->where('ozel_gun', false)->count() / 2,
        ]);
    }

    public function test_dongu_girdisi_ve_motor_hatalari_anlasilir(): void
    {
        $k = $this->kur();
        $this->postJson(self::API.'/donguler/onizle', ['baslangic' => '2027-13-01', 'gun' => 0, 'kurallar' => 'A,Z'], $k['h'])
            ->assertStatus(422)
            ->assertJsonPath('details.fields.baslangic', 'Başlangıç tarihini seçin.')
            ->assertJsonPath('details.fields.kurallar', 'Bilinmeyen kural: Z');
        $this->postJson(self::API.'/donguler/onizle', ['baslangic' => '2027-01-01', 'gun' => 3, 'ikili_engeller' => [[$k['eczaneler'][0], $k['eczaneler'][0]]]], $k['h'])
            ->assertStatus(422)->assertJsonPath('details.fields.ikili_engeller', 'Her satırda iki farklı eczane seçin.');

        // Doğu'nun iki eczanesi de tarih engelli: H kuralı bölgeyi tıkar.
        $engel = fn (int $e) => ['eczane' => $e, 'baslangic' => '2027-01-02', 'bitis' => '2027-01-02'];
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-01', 'gun' => 3, 'kurallar' => 'H',
            'tarih_engelleri' => [$engel($k['eczaneler'][3]), $engel($k['eczaneler'][4])]], $k['h'])
            ->assertStatus(422)->assertJsonPath('code', 'nobet_motor')
            ->assertJsonPath('message', 'Doğu, 02.01.2027: uygun eczane kalmadı. Tıkayan kural: H (Eczane belirli tarihlerde nöbet tutmasın). Ayarları kontrol edin.');
        $this->assertSame(0, DB::table('nobet_donguler')->count());
    }

    public function test_pasif_eczane_donguye_girmez_nobetli_eczane_silinmez(): void
    {
        $k = $this->kur();
        [$topal] = $k['eczaneler'];
        $this->putJson(self::API.'/eczaneler/'.$topal, ['ad' => 'Lale Eczanesi', 'bolge_id' => $k['merkez'], 'aktif' => false], $k['h'])->assertOk()->assertJsonPath('aktif', false);
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-01', 'gun' => 30, 'kurallar' => 'B'], $k['h'])->assertOk();
        $this->assertSame(0, DB::table('nobet_nobetler')->where('eczane_id', $topal)->count());

        $yuksek = $k['eczaneler'][1];
        $this->deleteJson(self::API.'/eczaneler/'.$yuksek, [], $k['h'])->assertStatus(422)->assertJsonPath('code', 'nobet_eczane_nobetli');
        $this->deleteJson(self::API.'/eczaneler/'.$topal, [], $k['h'])->assertOk();
    }

    public function test_nobet_elle_ayni_bolgeden_baska_eczaneye_verilir(): void
    {
        $k = $this->kur();
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-04', 'gun' => 1], $k['h'])->assertOk();
        $nobet = DB::table('nobet_nobetler')->where('bolge_id', $k['merkez'])->first();
        $baska = collect(array_slice($k['eczaneler'], 0, 3))->first(fn ($e) => $e !== (int) $nobet->eczane_id);

        $this->putJson(self::API.'/nobetler/'.$nobet->id, ['eczane_id' => $k['eczaneler'][3]], $k['h'])
            ->assertStatus(422)->assertJsonPath('details.fields.eczane_id', 'Nöbet aynı bölgeden bir eczaneye verilebilir.');
        $this->putJson(self::API.'/nobetler/'.$nobet->id, ['eczane_id' => $nobet->eczane_id], $k['h'])->assertStatus(422)->assertJsonPath('code', 'nobet_ayni');
        $this->putJson(self::API.'/nobetler/'.$nobet->id, ['eczane_id' => $baska], $k['h'])->assertOk();

        $yeni = DB::table('nobet_nobetler')->where('id', $nobet->id)->first();
        $this->assertSame([$baska, Nobetler::ELLE, ['elle' => true, 'onceki' => (int) $nobet->eczane_id]], [(int) $yeni->eczane_id, $yeni->kaynak, json_decode($yeni->gerekce, true)]);
    }

    public function test_site_nobetci_listesi_herkese_acik_ve_devir_saatine_gore(): void
    {
        $k = $this->kur();
        $this->postJson(self::API.'/ozel-gunler', ['tarih' => '2027-01-05', 'ad' => 'Deneme bayramı'], $k['h'])->assertOk();
        $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-04', 'gun' => 5], $k['h'])->assertOk();

        // Devirden önce (02:00) dünün, sonra (09:00) bugünün nöbetçisi.
        $liste = new Nobetler;
        $gece = $liste->nobetciler(2, '08:30', new \DateTimeImmutable('2027-01-05 02:00'));
        $sabah = $liste->nobetciler(2, '08:30', new \DateTimeImmutable('2027-01-05 09:00'));
        $this->assertSame(['2027-01-04', '2027-01-05'], [$gece['bugun'], $sabah['bugun']]);
        $this->assertSame([null, 'Deneme bayramı'], array_column($gece['gunler'], 'ozel_gun'));
        $this->assertCount(2, $gece['gunler'][0]['nobetler']);
        $this->assertSame(['bolge_id', 'vardiya', 'eczane', 'telefon', 'adres', 'tarif', 'enlem', 'boylam'], array_keys($gece['gunler'][0]['nobetler'][0]));
        $this->assertSame('Merkez', $gece['bolgeler'][0]['ad']);
        $this->assertSame('08:30', $liste->nobetciler(1, 'saçma', new \DateTimeImmutable('2027-01-05 09:00'))['devir_saati']);

        // Uç girişsiz açık; gün sayısı en çok 31.
        $this->getJson('/v1/nobet/nobetciler?gun=500')->assertOk()->assertJsonCount(31, 'gunler')->assertJsonPath('devir_saati', '08:30');
    }

    public function test_dongu_silinince_nobetleri_de_gider(): void
    {
        $k = $this->kur();
        $id = $this->postJson(self::API.'/donguler', ['baslangic' => '2027-01-04', 'gun' => 3], $k['h'])->assertOk()->json('dongu.id');
        $this->getJson(self::API.'/donguler', $k['h'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.atama_sayisi', 6);

        $this->deleteJson(self::API.'/donguler/'.$id, [], $k['h'])->assertOk()->assertJsonPath('silinen_nobet', 6);
        $this->assertSame([0, 0], [DB::table('nobet_nobetler')->count(), DB::table('nobet_donguler')->count()]);
    }

    public function test_genel_bakista_oda_kurulum_adimlari(): void
    {
        $adimlar = fn () => collect($this->getJson('/v1/admin/overview', $this->admin())->assertOk()->json('steps'))
            ->filter(fn ($a) => str_starts_with($a['key'], 'nobet-'))->pluck('done', 'key')->all();
        $this->assertSame(['nobet-oda' => false, 'nobet-bolgeler' => false, 'nobet-eczaneler' => false, 'nobet-ozel-gunler' => false, 'nobet-dongu' => false], $adimlar());
        $ilk = $this->getJson('/v1/admin/overview', $this->admin())->json('steps.0');
        $this->assertSame(['nobet-oda', 'projectSettings'], [$ilk['key'], $ilk['route']]);

        $k = $this->kur();
        $this->postJson(self::API.'/ozel-gunler', ['tarih' => now()->year.'-05-01', 'ad' => 'Emek ve Dayanışma Günü'], $k['h'])->assertOk();
        $this->postJson(self::API.'/donguler', ['baslangic' => now()->toDateString(), 'gun' => 20], $k['h'])->assertOk();
        $this->assertSame(['nobet-oda' => false, 'nobet-bolgeler' => true, 'nobet-eczaneler' => true, 'nobet-ozel-gunler' => true, 'nobet-dongu' => true], $adimlar());

        // Editör (nöbet yetkisi yok) nöbet adımlarını görmez.
        $this->assertSame([], collect($this->getJson('/v1/admin/overview', $this->as('editor'))->json('steps'))->filter(fn ($a) => str_starts_with($a['key'], 'nobet-'))->all());
    }

    public function test_genel_bakista_panel_rehberi_yetkiye_gore(): void
    {
        $rehber = fn (array $h) => collect($this->getJson('/v1/admin/dashboard', $h)->assertOk()->json('widgets'))->firstWhere('key', 'nobet-rehber');
        $yonetici = $rehber($this->admin());
        $this->assertSame('list', $yonetici['type']);
        $basliklar = array_column($yonetici['data']['items'], 'title');
        $this->assertContains('Nöbet döngüsü', $basliklar);
        $this->assertContains('Kullanıcılar', $basliklar);
        $this->assertSame(['route' => 'nobet', 'params' => ['tab' => 'dongu']], array_intersect_key($yonetici['data']['items'][1], ['route' => 1, 'params' => 1]));
        $this->assertSame([], array_filter(array_column($yonetici['data']['items'], 'url')), 'yalnızca panelin ekranları');

        // Nöbet yetkisi olmayan kartı görmez.
        $this->assertNull($rehber($this->as('editor')));
    }
}
