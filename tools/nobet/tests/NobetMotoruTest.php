<?php

namespace KodmobTools\Nobet\Tests;

use DateTimeImmutable;
use KodmobTools\Nobet\Motor\Atama;
use KodmobTools\Nobet\Motor\DonguIstegi;
use KodmobTools\Nobet\Motor\Eczane;
use KodmobTools\Nobet\Motor\Kural;
use KodmobTools\Nobet\Motor\MotorHatasi;
use KodmobTools\Nobet\Motor\NobetMotoru;
use KodmobTools\Nobet\Motor\Sayaclar;
use KodmobTools\Nobet\Motor\Takvim;
use Tests\TestCase;

/** Nöbet motoru: her kuralın davranışı ve eski sistemde hatalı olup düzeltilen durumlar. Motor veritabanına bakmaz; çatının TestCase'i paketin ad alanını bağlamak için. */
class NobetMotoruTest extends TestCase
{
    /**
     * Bölge → eczane sayısı; kimlikler 1'den sırayla. `grup`: eczanenin grubu (kimlik → grup), verilmezse grupsuz.
     *
     * @param  array<int, int>  $bolgeler
     * @param  array<int, int>  $grup
     * @return list<Eczane>
     */
    private function eczaneler(array $bolgeler, array $grup = []): array
    {
        $liste = [];
        $id = 1;
        foreach ($bolgeler as $bolge => $adet) {
            for ($i = 0; $i < $adet; $i++, $id++) {
                $liste[] = new Eczane($id, $bolge, $grup[$id] ?? null);
            }
        }

        return $liste;
    }

    /**
     * @param  list<Eczane>  $eczaneler
     * @param  array<string, mixed>  $ek  DonguIstegi'nin öteki adlı parametreleri
     */
    private function istek(array $eczaneler, string $kurallar, string $baslangic, string $bitis, array $ek = []): DonguIstegi
    {
        return new DonguIstegi(...[
            'baslangic' => new DateTimeImmutable($baslangic),
            'bitis' => new DateTimeImmutable($bitis),
            'eczaneler' => $eczaneler,
            'kurallar' => Kural::listeden($kurallar),
            'tohum' => 42,
            ...$ek,
        ]);
    }

    /** @return list<Atama> */
    private function calistir(DonguIstegi $istek): array
    {
        return (new NobetMotoru)->olustur($istek)->sirali();
    }

    /** @return array<string, list<Atama>> Y-m-d → o günün atamaları */
    private function gunlere(array $atamalar): array
    {
        $gunler = [];
        foreach ($atamalar as $a) {
            $gunler[$a->tarih->format('Y-m-d')][] = $a;
        }

        return $gunler;
    }

    public function test_her_gun_her_bolgeye_bir_nobetci_kendi_bolgesinden(): void
    {
        $eczaneler = $this->eczaneler([1 => 5, 2 => 4]);
        $atamalar = $this->calistir($this->istek($eczaneler, '', '2026-01-05', '2026-01-18'));

        $this->assertCount(28, $atamalar);
        $bolgesi = [];
        foreach ($eczaneler as $e) {
            $bolgesi[$e->id] = $e->bolgeId;
        }
        foreach ($this->gunlere($atamalar) as $gun => $o) {
            $this->assertSame([1, 2], array_map(fn (Atama $a) => $a->bolgeId, $o), $gun);
            foreach ($o as $a) {
                $this->assertSame($bolgesi[$a->eczaneId], $a->bolgeId);
                $this->assertSame(Atama::TUM_GUN, $a->vardiya);
            }
        }
    }

    public function test_ayni_tohum_ayni_takvimi_verir_girdi_degismez(): void
    {
        $sayaclar = new Sayaclar([1 => ['pazartesi' => 2]]);
        $istek = $this->istek($this->eczaneler([1 => 6, 2 => 6]), 'A,B,C,D,E', '2026-03-01', '2026-04-30', ['sayaclar' => $sayaclar]);
        $dizi = fn (array $l) => array_map(fn (Atama $a) => $a->dizi(), $l);

        $this->assertSame($dizi($this->calistir($istek)), $dizi($this->calistir($istek)));
        $this->assertSame([1 => ['pazartesi' => 2]], $sayaclar->hepsi());

        $baska = $this->istek($this->eczaneler([1 => 6, 2 => 6]), 'A,B,C,D,E', '2026-03-01', '2026-04-30', ['tohum' => 7]);
        $this->assertNotSame($dizi($this->calistir($istek)), $dizi($this->calistir($baska)));
    }

    public function test_tohum_verilmezse_uretilir_ve_sonucta_doner(): void
    {
        $istek = $this->istek($this->eczaneler([1 => 3]), 'B', '2026-01-05', '2026-01-11', ['tohum' => null]);
        $sonuc = (new NobetMotoru)->olustur($istek);
        $tekrar = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 3]), 'B', '2026-01-05', '2026-01-11', ['tohum' => $sonuc->tohum]));

        $this->assertSame(array_map(fn ($a) => $a->dizi(), $sonuc->atamalar), array_map(fn ($a) => $a->dizi(), $tekrar->atamalar));
    }

    public function test_once_cumartesiler_sonra_pazarlar_sonra_hafta_ici_atanir(): void
    {
        $sonuc = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 4]), '', '2026-01-05', '2026-01-18'));
        $gunler = array_map(fn (Atama $a) => (int) $a->tarih->format('N'), $sonuc->atamalar);

        $this->assertSame([6, 6, 7, 7], array_slice($gunler, 0, 4));
        $this->assertNotContains(6, array_slice($gunler, 4));
        $this->assertNotContains(7, array_slice($gunler, 4));
    }

    public function test_b_her_gunu_eczanelere_esit_dagitir(): void
    {
        // 4 eczane, 4 hafta: her eczane her gün adından tam bir nöbet.
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 4]), 'B', '2026-01-05', '2026-02-01'));

        $sayi = [];
        foreach ($atamalar as $a) {
            $sayi[$a->eczaneId][Takvim::gun($a->tarih)] = ($sayi[$a->eczaneId][Takvim::gun($a->tarih)] ?? 0) + 1;
        }
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame(array_fill_keys(Takvim::GUNLER, 1), array_merge(array_fill_keys(Takvim::GUNLER, 0), $sayi[$id]), "eczane {$id}");
        }
    }

    public function test_gecmis_sayaclar_dikkate_alinir(): void
    {
        $sayaclar = new Sayaclar([1 => ['cumartesi' => 5]]);
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 3]), 'B', '2026-01-03', '2026-01-17', ['sayaclar' => $sayaclar]));

        foreach ($atamalar as $a) {
            if (Takvim::gun($a->tarih) === 'cumartesi') {
                $this->assertNotSame(1, $a->eczaneId, 'geçmişte çok cumartesi tutan eczane seçilmemeli');
            }
        }
    }

    public function test_a_ayni_hafta_ikinci_nobet_vermez(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 7]), 'A', '2026-01-05', '2026-01-11'));

        $this->assertCount(7, array_unique(array_map(fn (Atama $a) => $a->eczaneId, $atamalar)));
    }

    public function test_a_eczane_yetmezse_esnetilir_ve_uyarir(): void
    {
        $sonuc = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 3]), 'A', '2026-01-05', '2026-01-11'));

        $this->assertCount(7, $sonuc->atamalar);
        $this->assertNotEmpty(array_filter($sonuc->uyarilar, fn ($u) => str_contains($u, 'A kuralı esnetildi')));
    }

    public function test_m_ayni_ay_ikinci_nobet_vermez(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 31]), 'M', '2026-01-01', '2026-01-31'));

        $this->assertCount(31, array_unique(array_map(fn (Atama $a) => $a->eczaneId, $atamalar)));
    }

    public function test_n_donem_icinde_nobetleri_esitler(): void
    {
        // 30 gün, 1 bölge, 3 eczane: kişi başı 30·1/3 = 10.
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 3]), 'N', '2026-01-01', '2026-01-30'));

        $sayi = array_count_values(array_map(fn (Atama $a) => $a->eczaneId, $atamalar));
        ksort($sayi);
        $this->assertSame([1 => 10, 2 => 10, 3 => 10], $sayi);
    }

    public function test_g_iki_eczane_ayni_gun_nobetci_olmaz(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 2, 2 => 2]), 'G', '2026-01-01', '2026-02-28', [
            'ikiliEngeller' => [[1, 3]],
        ]));

        foreach ($this->gunlere($atamalar) as $gun => $o) {
            $idler = array_map(fn (Atama $a) => $a->eczaneId, $o);
            $this->assertFalse(in_array(1, $idler, true) && in_array(3, $idler, true), $gun);
        }
    }

    public function test_h_tarih_engeli_nobet_gunune_bakar(): void
    {
        // Eski sistemde engel döngünün bitişinin ertesi gününe bakıyordu; aralıktaki günlerde etkisizdi.
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 2]), 'H', '2026-01-01', '2026-01-10', [
            'tarihEngelleri' => [['eczane' => 1, 'baslangic' => '2026-01-03', 'bitis' => '2026-01-05']],
        ]));

        foreach ($atamalar as $a) {
            if ($a->tarih->format('Y-m-d') >= '2026-01-03' && $a->tarih->format('Y-m-d') <= '2026-01-05') {
                $this->assertSame(2, $a->eczaneId, $a->tarih->format('Y-m-d'));
            }
        }
    }

    public function test_kural_butun_adaylari_elerse_aciklayan_hata_verir(): void
    {
        $this->expectException(MotorHatasi::class);
        $this->expectExceptionMessage('Tıkayan kural: H');

        (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 1]), 'H', '2026-01-01', '2026-01-10', [
            'tarihEngelleri' => [['eczane' => 1, 'baslangic' => '2026-01-04', 'bitis' => '2026-01-04']],
        ]), [1 => 'Merkez']);
    }

    public function test_i_grup_engeli_arayuzun_gonderdigi_ciftlerle_calisir(): void
    {
        // Eski sistem çiftleri ['grup1', 'grup2'] anahtarıyla okuyordu; arayüz [g1, g2] gönderdiği için çöküyordu.
        $eczaneler = $this->eczaneler([1 => 2, 2 => 2], [1 => 11, 2 => 12, 3 => 21, 4 => 22]);
        $atamalar = $this->calistir($this->istek($eczaneler, 'I', '2026-01-01', '2026-02-28', ['grupEngelleri' => [[11, 21]]]));

        foreach ($this->gunlere($atamalar) as $gun => $o) {
            $gruplar = array_map(fn (Atama $a) => $a->grupId, $o);
            $this->assertFalse(in_array(11, $gruplar, true) && in_array(21, $gruplar, true), $gun);
        }
    }

    public function test_j_sonraki_bolgeler_ilk_nobetcinin_es_ve_yedek_gruplarindan(): void
    {
        $eczaneler = $this->eczaneler([1 => 2, 2 => 3], [1 => 11, 2 => 12, 3 => 21, 4 => 22, 5 => 23]);
        $gruplar = [11 => ['eslesme' => [21]], 12 => ['eslesme' => [22], 'yedek' => 23]];
        $atamalar = $this->calistir($this->istek($eczaneler, 'J', '2026-01-01', '2026-02-28', ['gruplar' => $gruplar]));

        $gorulen = [];
        foreach ($this->gunlere($atamalar) as $gun => [$ilk, $ikinci]) {
            $izinli = $ilk->grupId === 11 ? [21] : [22, 23];
            $this->assertContains($ikinci->grupId, $izinli, $gun);
            $gorulen[$ikinci->grupId] = true;
        }
        $this->assertArrayHasKey(23, $gorulen, 'yedek grup da seçilebilmeli');
    }

    public function test_bolgede_ayni_gun_ayni_gruptan_ikinci_nobetci_secilmez(): void
    {
        $eczaneler = $this->eczaneler([1 => 4], [1 => 11, 2 => 11, 3 => 12, 4 => 12]);
        $atamalar = $this->calistir($this->istek($eczaneler, 'L', '2026-01-03', '2026-01-25'));

        foreach ($this->gunlere($atamalar) as $gun => $o) {
            if (count($o) === 2) {
                $this->assertNotSame($o[0]->grupId, $o[1]->grupId, $gun);
            }
        }
    }

    public function test_k_cumartesi_gunduz_ve_gece_bolge_sayilariyla(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 5, 2 => 5]), 'K', '2026-01-02', '2026-01-04', [
            'cumartesiGunduzSayisi' => [1 => 2, 2 => 0],
        ]));
        $cumartesi = array_filter($atamalar, fn (Atama $a) => $a->tarih->format('Y-m-d') === '2026-01-03');
        $say = fn (string $v, int $b) => count(array_filter($cumartesi, fn (Atama $a) => $a->vardiya === $v && $a->bolgeId === $b));

        $this->assertSame([2, 0, 1, 1], [$say(Atama::GUNDUZ, 1), $say(Atama::GUNDUZ, 2), $say(Atama::GECE, 1), $say(Atama::GECE, 2)]);
        $this->assertCount(0, array_filter($cumartesi, fn (Atama $a) => $a->vardiya === Atama::TUM_GUN));
        $this->assertCount(4, array_filter($atamalar, fn (Atama $a) => $a->vardiya === Atama::TUM_GUN), 'cuma ve pazar tüm gün');
    }

    public function test_l_hafta_sonu_ek_nobetci_ayni_eczaneyi_iki_kez_secmez(): void
    {
        // Eski sistemde ikinci tur günün seçimlerini bilmiyordu; aynı eczane aynı gün iki kez yazılabiliyordu.
        foreach (range(1, 20) as $tohum) {
            $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 3]), 'L', '2026-01-02', '2026-01-04', ['tohum' => $tohum]));
            $gunler = $this->gunlere($atamalar);

            $this->assertCount(1, $gunler['2026-01-02']);
            foreach (['2026-01-03', '2026-01-04'] as $gun) {
                $this->assertCount(2, $gunler[$gun]);
                $this->assertNotSame($gunler[$gun][0]->eczaneId, $gunler[$gun][1]->eczaneId, "tohum {$tohum}, {$gun}");
            }
        }
    }

    public function test_o_ozel_gunler_bos_birakilir(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 3]), 'O', '2026-01-01', '2026-01-05', [
            'ozelGunler' => ['2026-01-01'],
        ]));

        $this->assertArrayNotHasKey('2026-01-01', $this->gunlere($atamalar));
        $this->assertCount(4, $atamalar);
    }

    public function test_ozel_gun_nobeti_isaretlenir_ve_sayaca_girmez(): void
    {
        // İki pazartesi, ilki özel gün, iki eczane, B kuralı. Özel gün sayılsaydı ikinci pazartesi hep öteki
        // eczaneye giderdi; sayılmadığı için bazı kuralarda aynı eczane iki pazartesiyi de alır.
        $ayniEczane = false;
        foreach (range(1, 30) as $tohum) {
            $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 2]), 'B', '2026-01-05', '2026-01-12', [
                'ozelGunler' => ['2026-01-05'], 'tohum' => $tohum,
            ]));
            $gunler = $this->gunlere($atamalar);
            $this->assertTrue($gunler['2026-01-05'][0]->ozelGun);
            $this->assertFalse($gunler['2026-01-12'][0]->ozelGun);
            $ayniEczane = $ayniEczane || $gunler['2026-01-05'][0]->eczaneId === $gunler['2026-01-12'][0]->eczaneId;
        }
        $this->assertTrue($ayniEczane);
    }

    public function test_nobetcisi_onceden_yazilmis_gunler_atlanir(): void
    {
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 3]), '', '2026-01-01', '2026-01-05', [
            'doluGunler' => ['2026-01-02', '2026-01-03'],
        ]));

        $this->assertSame(['2026-01-01', '2026-01-04', '2026-01-05'], array_keys($this->gunlere($atamalar)));
    }

    public function test_p_ayni_gun_nobetcilere_yakin_olani_secmez(): void
    {
        // Eski sistemde coğrafi kural O harfine bağlıydı; P seçilince hiçbir şey olmuyordu.
        $eczaneler = [
            new Eczane(1, 1, null, 37.0000, 36.0000),
            new Eczane(2, 2, null, 37.0010, 36.0010),   // ~0,14 km
            new Eczane(3, 2, null, 37.0900, 36.0900),   // ~12,7 km
        ];
        $atamalar = $this->calistir($this->istek($eczaneler, 'P', '2026-01-01', '2026-01-31'));

        foreach ($atamalar as $a) {
            if ($a->bolgeId === 2) {
                $this->assertSame(3, $a->eczaneId);
                $this->assertSame([['kural' => 'P', 'once' => 2, 'sonra' => 1]], $a->gerekce['adimlar']);
            }
        }
    }

    public function test_gerekce_kurallarin_adaylari_nasil_daralttigini_anlatir(): void
    {
        $sayaclar = new Sayaclar([1 => ['cuma' => 3], 2 => ['cuma' => 1], 3 => ['cuma' => 1], 4 => ['cuma' => 1]]);
        $atamalar = $this->calistir($this->istek($this->eczaneler([1 => 4]), 'A,B', '2026-01-02', '2026-01-02', ['sayaclar' => $sayaclar]));

        $this->assertSame(['adimlar' => [['kural' => 'B', 'once' => 4, 'sonra' => 3]], 'kura' => 3], $atamalar[0]->gerekce);
    }

    public function test_gercekci_uc_aylik_dongu_dengeli(): void
    {
        // 3 bölge (9, 8, 8 eczane), 91 gün, odaların sık kullandığı kurallar.
        $sonuc = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 9, 2 => 8, 3 => 8]), 'A,N,B,C,D,E', '2026-01-01', '2026-04-01'));

        $this->assertCount(91 * 3, $sonuc->atamalar);
        $toplam = $cumartesi = $pazar = [];
        foreach ($sonuc->atamalar as $a) {
            $toplam[$a->bolgeId][$a->eczaneId] = ($toplam[$a->bolgeId][$a->eczaneId] ?? 0) + 1;
            $gun = Takvim::gun($a->tarih);
            if ($gun === 'cumartesi') {
                $cumartesi[$a->bolgeId][$a->eczaneId] = ($cumartesi[$a->bolgeId][$a->eczaneId] ?? 0) + 1;
            } elseif ($gun === 'pazar') {
                $pazar[$a->bolgeId][$a->eczaneId] = ($pazar[$a->bolgeId][$a->eczaneId] ?? 0) + 1;
            }
        }
        foreach ([1 => 9, 2 => 8, 3 => 8] as $bolge => $adet) {
            $this->assertCount($adet, $toplam[$bolge], "bölge {$bolge}: herkes nöbet almalı");
            // 200 tohumla ölçüldü: hafta sonu her tohumda en çok 1, toplam en çok 2 fark (A hafta içini kısıtlıyor).
            $this->assertLessThanOrEqual(2, max($toplam[$bolge]) - min($toplam[$bolge]), "bölge {$bolge} toplam");
            foreach (['cumartesi' => $cumartesi, 'pazar' => $pazar] as $ad => $sayilar) {
                $liste = array_pad(array_values($sayilar[$bolge]), $adet, 0);
                $this->assertLessThanOrEqual(1, max($liste) - min($liste), "bölge {$bolge} {$ad}");
            }
        }
    }

    public function test_r_iki_nobet_arasinda_en_az_gun_birakir(): void
    {
        $sonuc = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 10]), 'R,B', '2026-01-05', '2026-03-01', ['enAzAralikGun' => 7]));

        $this->assertSame([], $sonuc->uyarilar);
        $gunleri = [];
        foreach ($sonuc->sirali() as $a) {
            $gunleri[$a->eczaneId][] = $a->tarih;
        }
        foreach ($gunleri as $eczane => $tarihler) {
            for ($i = 1; $i < count($tarihler); $i++) {
                $this->assertGreaterThanOrEqual(7, $tarihler[$i - 1]->diff($tarihler[$i])->days, "eczane {$eczane}");
            }
        }
    }

    public function test_r_dongu_disindaki_nobetlere_iki_yandan_bakar(): void
    {
        $eczaneler = $this->eczaneler([1 => 2]);
        // 1 numara iki gün önce nöbetçiydi; sonra (önceden yazılmış) üç gün sonra nöbetçi.
        foreach ([['2026-01-03'], ['2026-01-08']] as $bilinen) {
            $atama = $this->calistir($this->istek($eczaneler, 'R', '2026-01-05', '2026-01-05', ['bilinenNobetler' => [1 => $bilinen]]))[0];
            $this->assertSame(2, $atama->eczaneId);
        }
        // Aralık kısaltılınca (2 gün) 1 numara da aday.
        $adaylar = [];
        foreach (range(1, 20) as $tohum) {
            $adaylar[] = $this->calistir($this->istek($eczaneler, 'R', '2026-01-05', '2026-01-05', ['bilinenNobetler' => [1 => ['2026-01-03']], 'enAzAralikGun' => 2, 'tohum' => $tohum]))[0]->eczaneId;
        }
        sort($adaylar);
        $this->assertSame([1, 2], array_values(array_unique($adaylar)));
    }

    public function test_r_eczane_yetmezse_en_uzak_olani_secer_ve_bir_kez_uyarir(): void
    {
        $sonuc = (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 3]), 'R', '2026-01-05', '2026-01-11'));

        $this->assertCount(7, $sonuc->atamalar);
        $this->assertSame(['R kuralı: 4 atamada 7 günlük aralık sağlanamadı (bölgedeki eczane sayısı yetmiyor); son nöbetinden en uzak olan seçildi.'], $sonuc->uyarilar);
        // Esnetilince de en uzak olan seçilir: hiçbir eczane art arda iki gün nöbetçi olmaz.
        $gunleri = [];
        foreach ($sonuc->sirali() as $a) {
            $gunleri[$a->eczaneId][] = $a->tarih;
        }
        foreach ($gunleri as $tarihler) {
            for ($i = 1; $i < count($tarihler); $i++) {
                $this->assertGreaterThanOrEqual(2, $tarihler[$i - 1]->diff($tarihler[$i])->days);
            }
        }
    }

    public function test_s_en_uzun_suredir_nobet_tutmayani_secer(): void
    {
        $bilinen = [1 => ['2025-12-20'], 2 => ['2025-12-30', '2026-01-20'], 3 => ['2025-12-28']];
        $atama = fn (array $eczaneler) => $this->calistir($this->istek($eczaneler, 'S', '2026-01-05', '2026-01-05', ['bilinenNobetler' => $bilinen]))[0]->eczaneId;

        // Son nöbeti en eski olan 1 (2'nin sonraki nöbeti sayılmaz: o günden önceki son nöbete bakılır).
        $this->assertSame(1, $atama($this->eczaneler([1 => 3])));
        // Hiç nöbeti olmayan (4) en önce.
        $this->assertSame(4, $atama($this->eczaneler([1 => 4])));

        // Döngü içinde de: dört eczane, S tek başına → sırayla dönerler (her dört günde bir).
        $sonuc = $this->calistir($this->istek($this->eczaneler([1 => 4]), 'S', '2026-01-05', '2026-01-09', ['bilinenNobetler' => [1 => ['2026-01-01'], 2 => ['2026-01-02'], 3 => ['2026-01-03'], 4 => ['2026-01-04']]]));
        $this->assertSame([1, 2, 3, 4, 1], array_map(fn (Atama $a) => $a->eczaneId, $sonuc));
    }

    public function test_kural_harfleri_okunur(): void
    {
        $this->assertSame([Kural::AyniHafta, Kural::GunSayisi, Kural::CografiDenge], Kural::listeden(' A, b ,A,P'));
        $this->assertTrue(Kural::OzelGunlerBos->yapisal());
        $this->assertFalse(Kural::CografiDenge->yapisal());

        $this->expectException(MotorHatasi::class);
        Kural::listeden('A,Z');
    }

    public function test_bos_ya_da_ters_aralik_hata(): void
    {
        $this->expectException(MotorHatasi::class);
        (new NobetMotoru)->olustur($this->istek($this->eczaneler([1 => 2]), '', '2026-01-10', '2026-01-01'));
    }
}
