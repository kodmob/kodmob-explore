<?php

namespace KodmobTools\Nobet\Motor;

use DateTimeImmutable;
use DateTimeZone;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Nöbet döngüsü: verilen tarih aralığında her güne, her bölgeye nöbetçi eczane yazar.
 *
 * Akış (eski sistemle aynı): önce bütün cumartesiler, sonra pazarlar, sonra hafta içi günler atanır (hafta sonu
 * yükü en adil dağılması gereken yük olduğu için ilk o dağıtılır). Her gün için bölgeler sırayla dolaşılır; bölgenin
 * adayları o gün seçilmemiş ve (mümkünse) aynı bölgede o gün seçilmiş gruptan olmayan eczanelerdir. Adaylar seçim
 * kurallarından sırayla geçer; kalanlar arasından tohumlu kurayla biri seçilir.
 *
 * Saf hesap: veritabanına yazmaz, girdiyi değiştirmez. Aynı istek ve tohum aynı sonucu verir.
 */
final class NobetMotoru
{
    private DonguIstegi $istek;

    private Randomizer $kura;

    private Sayaclar $sayaclar;

    /** @var array<int, array<string, list<int>>> bölge → ISO hafta → eczaneler (A) */
    private array $haftalik = [];

    /** @var array<int, array<string, list<int>>> bölge → ay → eczaneler (M) */
    private array $aylik = [];

    /** @var array<int, array<int, int>> bölge → eczane → bu döngüdeki nöbet sayısı (N) */
    private array $donem = [];

    /** @var array<int, list<int>> eczane → nöbet günleri (gün numarası; bilinen + bu döngüde yazılan) (R, S) */
    private array $gunleri = [];

    /** R'nin aralığı sağlayamadığı atama sayısı (döngü sonunda tek uyarı). */
    private int $aralikSaglanamayan = 0;

    /** @var list<Atama> */
    private array $atamalar = [];

    /** @var list<string> */
    private array $uyarilar = [];

    /** @var array<int, int> bölge → eczane sayısı (N) */
    private array $bolgeBuyuklugu = [];

    /** @var array<int, string> bölge → ad (mesajlar için; verilmezse "Bölge 3") */
    private array $bolgeAdlari = [];

    /** @param array<int, string> $bolgeAdlari */
    public function olustur(DonguIstegi $istek, array $bolgeAdlari = []): DonguSonucu
    {
        if ($istek->bitis < $istek->baslangic) {
            throw new MotorHatasi('Bitiş tarihi başlangıçtan önce olamaz.');
        }
        if ($istek->eczaneler === []) {
            throw new MotorHatasi('Döngüye katılan etkin eczane yok.');
        }

        $tohum = $istek->tohum ?? random_int(1, PHP_INT_MAX);
        $this->istek = $istek;
        $this->kura = new Randomizer(new Mt19937($tohum));
        $this->sayaclar = clone $istek->sayaclar;
        $this->haftalik = $this->aylik = $this->donem = [];
        $this->atamalar = $this->uyarilar = [];
        $this->bolgeAdlari = $bolgeAdlari;
        $this->gunleri = [];
        $this->aralikSaglanamayan = 0;
        foreach ($istek->bilinenNobetler as $eczaneId => $tarihler) {
            foreach ($tarihler as $t) {
                $this->gunleri[(int) $eczaneId][] = self::gunNo(new DateTimeImmutable(substr((string) $t, 0, 10)));
            }
        }

        $bolgeler = [];
        foreach ($istek->eczaneler as $eczane) {
            $bolgeler[$eczane->bolgeId][] = $eczane;
        }
        $this->bolgeBuyuklugu = array_map('count', $bolgeler);

        foreach ($this->gunler() as [$tarih, $vardiya, $ozel]) {
            $this->gunuAta($tarih, $vardiya, $ozel, $bolgeler);
        }
        if ($this->aralikSaglanamayan > 0) {
            $this->uyarilar[] = sprintf('R kuralı: %d atamada %d günlük aralık sağlanamadı (bölgedeki eczane sayısı yetmiyor); son nöbetinden en uzak olan seçildi.',
                $this->aralikSaglanamayan, max(1, $istek->enAzAralikGun));
        }

        return new DonguSonucu($this->atamalar, $this->uyarilar, $tohum);
    }

    /**
     * Atanacak günler atama sırasıyla: cumartesiler (K açıksa gündüz ve gece ayrı), pazarlar, hafta içi.
     *
     * @return list<array{0: DateTimeImmutable, 1: string, 2: bool}>
     */
    private function gunler(): array
    {
        $ozelGunler = array_flip($this->istek->ozelGunler);
        $doluGunler = array_flip($this->istek->doluGunler);
        $cumartesiAyri = $this->istek->var(Kural::CumartesiGunduzGece);
        $ozelBos = $this->istek->var(Kural::OzelGunlerBos);

        $cumartesi = $pazar = $haftaici = [];
        $bitis = $this->istek->bitis->setTime(0, 0);
        for ($t = $this->istek->baslangic->setTime(0, 0); $t <= $bitis; $t = $t->modify('+1 day')) {
            $gun = $t->format('Y-m-d');
            $ozel = isset($ozelGunler[$gun]);
            if (($ozel && $ozelBos) || isset($doluGunler[$gun])) {
                continue;
            }
            $haftaGunu = (int) $t->format('N');
            if ($haftaGunu === 6 && $cumartesiAyri) {
                $cumartesi[] = [$t, Atama::GUNDUZ, $ozel];
                $cumartesi[] = [$t, Atama::GECE, $ozel];
            } elseif ($haftaGunu === 6) {
                $cumartesi[] = [$t, Atama::TUM_GUN, $ozel];
            } elseif ($haftaGunu === 7) {
                $pazar[] = [$t, Atama::TUM_GUN, $ozel];
            } else {
                $haftaici[] = [$t, Atama::TUM_GUN, $ozel];
            }
        }

        return array_merge($cumartesi, $pazar, $haftaici);
    }

    /** @param array<int, list<Eczane>> $bolgeler */
    private function gunuAta(DateTimeImmutable $tarih, string $vardiya, bool $ozel, array $bolgeler): void
    {
        $gun = new GunDurumu;
        // L: hafta sonu (tüm gün vardiyasında) her bölgeye bir tur daha; ikinci tur günün seçimlerini bilir.
        $turlar = $this->istek->var(Kural::HaftasonuEkNobetci) && $vardiya === Atama::TUM_GUN && Takvim::haftasonu($tarih) ? 2 : 1;
        // K: cumartesi gündüz nöbetçi sayısı bölgeye göre (yazılmayan bölgede 1; 0 yazılan bölgeye gündüz nöbetçisi yok).
        $adetler = $vardiya === Atama::GUNDUZ ? $this->istek->cumartesiGunduzSayisi : [];

        for ($tur = 0; $tur < $turlar; $tur++) {
            foreach ($bolgeler as $bolgeId => $eczaneler) {
                $adet = $adetler[$bolgeId] ?? 1;
                for ($i = 0; $i < $adet; $i++) {
                    if (! $this->ata($tarih, $vardiya, $ozel, $bolgeId, $eczaneler, $gun)) {
                        break;
                    }
                }
            }
        }
    }

    /** @param list<Eczane> $eczaneler */
    private function ata(DateTimeImmutable $tarih, string $vardiya, bool $ozel, int $bolgeId, array $eczaneler, GunDurumu $gun): bool
    {
        $adaylar = $gun->adaylar($bolgeId, $eczaneler, true);
        if ($adaylar === []) {
            $adaylar = $gun->adaylar($bolgeId, $eczaneler, false);
            if ($adaylar !== []) {
                $this->uyar($tarih, $bolgeId, 'o gün seçilmemiş başka grup kalmadı, aynı gruptan seçildi');
            }
        }
        if ($adaylar === []) {
            $this->uyar($tarih, $bolgeId, 'o gün seçilmemiş eczane kalmadı, eksik nöbetçi');

            return false;
        }

        $adimlar = [];
        foreach ($this->istek->kurallar as $kural) {
            if ($kural->yapisal()) {
                continue;
            }
            $once = count($adaylar);
            $kalan = array_values($this->uygula($kural, $adaylar, $tarih, $bolgeId, $gun));
            if ($kalan === []) {
                if ($kural === Kural::AyniHafta || $kural === Kural::AyniAy) {
                    // Bölgedeki eczane sayısı haftanın / ayın nöbetini karşılamıyorsa kural takvimi tıkamasın.
                    $this->uyar($tarih, $bolgeId, "{$kural->value} kuralı esnetildi (uygun eczane kalmadı)");

                    continue;
                }
                throw new MotorHatasi(sprintf('%s, %s: uygun eczane kalmadı. Tıkayan kural: %s (%s). Ayarları kontrol edin.',
                    $this->bolgeAdi($bolgeId), $tarih->format('d.m.Y'), $kural->value, $kural->etiket()));
            }
            if (count($kalan) < $once) {
                $adimlar[] = ['kural' => $kural->value, 'once' => $once, 'sonra' => count($kalan)];
            }
            $adaylar = $kalan;
        }

        $secilen = $adaylar[count($adaylar) === 1 ? 0 : $this->kura->getInt(0, count($adaylar) - 1)];
        $this->kaydet($secilen, $tarih, $vardiya, $ozel, $gun, ['adimlar' => $adimlar, 'kura' => count($adaylar)]);

        return true;
    }

    /**
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function uygula(Kural $kural, array $adaylar, DateTimeImmutable $tarih, int $bolgeId, GunDurumu $gun): array
    {
        return match ($kural) {
            Kural::AyniHafta => $this->disinda($adaylar, $this->haftalik[$bolgeId][Takvim::hafta($tarih)] ?? []),
            Kural::AyniAy => $this->disinda($adaylar, $this->aylik[$bolgeId][Takvim::ayKimligi($tarih)] ?? []),
            Kural::DonemDengesi => $this->donemDengesi($adaylar, $bolgeId),
            Kural::GunSayisi => $this->enAz($adaylar, Takvim::gun($tarih), true),
            Kural::MevsimSayisi => $this->enAz($adaylar, Takvim::mevsim($tarih), false),
            Kural::AySayisi => $this->enAz($adaylar, Takvim::ay($tarih), true),
            Kural::HaftaTipiSayisi => $this->enAz($adaylar, Takvim::haftaTipi($tarih), true),
            Kural::IkiliEczaneEngeli => $this->disinda($adaylar, $this->ikiliEngelliler($gun)),
            Kural::TarihAraligiEngeli => $this->disinda($adaylar, $this->tarihEngelliler($tarih)),
            Kural::GrupEngeli => $this->grupEngeli($adaylar, $gun),
            Kural::EsVeYedekGrup => $this->esVeYedekGrup($adaylar, $gun),
            Kural::CografiDenge => $this->cografiDenge($adaylar, $gun),
            Kural::EnAzAralik => $this->enAzAralik($adaylar, $tarih),
            Kural::EnUzunSuredir => $this->enUzunSuredir($adaylar, $tarih),
            default => $adaylar,
        };
    }

    /**
     * @param  list<Eczane>  $adaylar
     * @param  list<int>  $idler
     * @return array<int, Eczane>
     */
    private function disinda(array $adaylar, array $idler): array
    {
        if ($idler === []) {
            return $adaylar;
        }
        $idler = array_flip($idler);

        return array_filter($adaylar, fn (Eczane $e) => ! isset($idler[$e->id]));
    }

    /**
     * B, C, D, E: sayacı en düşük olanlar. `tekseAtla`: tek aday kaldıysa dokunma (eski sistemde C dışındakiler böyle).
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function enAz(array $adaylar, string $anahtar, bool $tekseAtla): array
    {
        if ($tekseAtla && count($adaylar) <= 1) {
            return $adaylar;
        }
        $en = min(array_map(fn (Eczane $e) => $this->sayaclar->sayi($e->id, $anahtar), $adaylar));

        return array_filter($adaylar, fn (Eczane $e) => $this->sayaclar->sayi($e->id, $anahtar) === $en);
    }

    /**
     * N: döngü boyunca bölgede eczane başına düşen ortalama nöbet a/c (a gün sayısı, c bölgenin eczane sayısı).
     * Döngüde bunun tam kısmından az nöbet tutmuş olanlar öncelikli; yoksa daraltmaz.
     *
     * Eski sistem oranı bütün oda için hesaplıyordu (gün × bölge sayısı / toplam eczane): bölgeler farklı
     * büyüklükteyse küçük bölgede eşik ortalamanın altında kalıyor, son nöbetler dengelenmiyordu.
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function donemDengesi(array $adaylar, int $bolgeId): array
    {
        $a = (int) $this->istek->baslangic->diff($this->istek->bitis)->days + 1;
        $c = max(1, $this->bolgeBuyuklugu[$bolgeId] ?? 1);
        if ($a <= $c) {
            return $adaylar;
        }
        $enAz = intdiv($a, $c);
        $oncelikli = array_filter($adaylar, fn (Eczane $e) => ($this->donem[$bolgeId][$e->id] ?? 0) < $enAz);

        return $oncelikli === [] ? $adaylar : $oncelikli;
    }

    /**
     * R: o güne `enAzAralikGun` günden yakın (önce ya da sonra) nöbeti olan eczaneler elenir. Atama sırası takvim
     * sırası değil (önce cumartesiler), bu yüzden iki yana da bakılır. Hepsi yakınsa kural düşmez: en yakın nöbeti en
     * uzakta olanlar kalır (döngü sonunda tek uyarı: kaç günde aralık sağlanamadı).
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function enAzAralik(array $adaylar, DateTimeImmutable $tarih): array
    {
        $bugun = self::gunNo($tarih);
        $aralik = max(1, $this->istek->enAzAralikGun);
        $uzaklik = [];
        foreach ($adaylar as $e) {
            $en = PHP_INT_MAX;
            foreach ($this->gunleri[$e->id] ?? [] as $g) {
                $en = min($en, abs($g - $bugun));
            }
            $uzaklik[$e->id] = $en;
        }
        $uygun = array_filter($adaylar, static fn (Eczane $e) => $uzaklik[$e->id] >= $aralik);
        if ($uygun !== []) {
            return $uygun;
        }
        $this->aralikSaglanamayan++;
        $en = max($uzaklik);

        return array_filter($adaylar, static fn (Eczane $e) => $uzaklik[$e->id] === $en);
    }

    /**
     * S: o günden önceki son nöbeti en eski olanlar (hiç nöbeti olmayan en önce). Sona konursa eşitlikte kuranın yerini alır.
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function enUzunSuredir(array $adaylar, DateTimeImmutable $tarih): array
    {
        if (count($adaylar) <= 1) {
            return $adaylar;
        }
        $bugun = self::gunNo($tarih);
        $son = [];
        foreach ($adaylar as $e) {
            $once = array_filter($this->gunleri[$e->id] ?? [], static fn (int $g) => $g < $bugun);
            $son[$e->id] = $once === [] ? PHP_INT_MIN : max($once);
        }
        $en = min($son);

        return array_filter($adaylar, static fn (Eczane $e) => $son[$e->id] === $en);
    }

    /** Takvim günü numarası (saat dilimi ve yaz saatinden bağımsız). */
    private static function gunNo(DateTimeImmutable $tarih): int
    {
        return intdiv((new DateTimeImmutable($tarih->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /** @return list<int> o gün seçilmiş eczanelerin ikili engeli olanlar (G) */
    private function ikiliEngelliler(GunDurumu $gun): array
    {
        $secilen = array_flip($gun->idler());
        $engelli = [];
        foreach ($this->istek->ikiliEngeller as [$bir, $iki]) {
            if (isset($secilen[$bir])) {
                $engelli[] = (int) $iki;
            }
            if (isset($secilen[$iki])) {
                $engelli[] = (int) $bir;
            }
        }

        return $engelli;
    }

    /** @return list<int> nöbet gününde tarih engeli olan eczaneler (H; iki uç dahil) */
    private function tarihEngelliler(DateTimeImmutable $tarih): array
    {
        $gun = $tarih->format('Y-m-d');
        $engelli = [];
        foreach ($this->istek->tarihEngelleri as $engel) {
            if ($gun >= substr($engel['baslangic'], 0, 10) && $gun <= substr($engel['bitis'], 0, 10)) {
                $engelli[] = (int) $engel['eczane'];
            }
        }

        return $engelli;
    }

    /**
     * I: o gün seçilmiş grupların engellediği gruplardan eczane seçilmez.
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function grupEngeli(array $adaylar, GunDurumu $gun): array
    {
        $secilen = array_flip($gun->gruplar());
        $engelli = [];
        foreach ($this->istek->grupEngelleri as [$bir, $iki]) {
            if (isset($secilen[$bir])) {
                $engelli[(int) $iki] = true;
            }
            if (isset($secilen[$iki])) {
                $engelli[(int) $bir] = true;
            }
        }

        return $engelli === [] ? $adaylar
            : array_filter($adaylar, fn (Eczane $e) => $e->grupId === null || ! isset($engelli[$e->grupId]));
    }

    /**
     * J: günün ilk nöbetçisinden sonrakiler, ilk nöbetçinin grubunun eş gruplarından ya da yedek grubundan seçilir.
     * İlk nöbetçinin grubunda eş / yedek tanımlı değilse daraltmaz.
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function esVeYedekGrup(array $adaylar, GunDurumu $gun): array
    {
        $ilkGrup = $gun->ilkGrup();
        if ($gun->bos() || $ilkGrup === null) {
            return $adaylar;
        }
        $grup = $this->istek->gruplar[$ilkGrup] ?? [];
        $izinli = array_map('intval', $grup['eslesme'] ?? []);
        if (! empty($grup['yedek'])) {
            $izinli[] = (int) $grup['yedek'];
        }
        if ($izinli === []) {
            return $adaylar;
        }
        $izinli = array_flip($izinli);

        return array_filter($adaylar, fn (Eczane $e) => $e->grupId !== null && isset($izinli[$e->grupId]));
    }

    /**
     * P: o gün seçilmiş nöbetçilere çok yakın olanları ele (en az `cografiEnAzKm`), kalanları "en yakın nöbetçiye
     * uzak, ortalama uzaklığı ötekilere benzer" puanıyla sırala, üst `cografiUstOran` dilimini bırak. Konumu
     * olmayan aday (puanlanamaz) puanlanan biri varken elenir. Hiç puanlanan kalmazsa daraltmaz.
     *
     * @param  list<Eczane>  $adaylar
     * @return array<int, Eczane>
     */
    private function cografiDenge(array $adaylar, GunDurumu $gun): array
    {
        if ($gun->bos()) {
            return $adaylar;
        }
        $olcumler = [];
        foreach ($adaylar as $aday) {
            $uzakliklar = array_values(array_filter(
                array_map(fn (Eczane $s) => $aday->uzaklik($s), $gun->secilenler()),
                fn (?float $d) => $d !== null,
            ));
            if ($uzakliklar === []) {
                continue;
            }
            $enYakin = min($uzakliklar);
            if ($this->istek->cografiEnAzKm > 0 && $enYakin < $this->istek->cografiEnAzKm) {
                continue;
            }
            $olcumler[$aday->id] = ['min' => $enYakin, 'ort' => array_sum($uzakliklar) / count($uzakliklar)];
        }
        if ($olcumler === []) {
            return $adaylar;
        }

        $hedef = array_sum(array_column($olcumler, 'ort')) / count($olcumler);
        $puanlar = array_map(fn (array $o) => $o['min'] - abs($o['ort'] - $hedef), $olcumler);
        arsort($puanlar);
        $oran = max(0.05, min($this->istek->cografiUstOran, 1.0));
        $esik = array_values($puanlar)[max(1, (int) ceil(count($puanlar) * $oran)) - 1];

        return array_filter($adaylar, fn (Eczane $e) => isset($puanlar[$e->id]) && $puanlar[$e->id] >= $esik);
    }

    /** @param array{adimlar: list<array{kural: string, once: int, sonra: int}>, kura: int} $gerekce */
    private function kaydet(Eczane $eczane, DateTimeImmutable $tarih, string $vardiya, bool $ozel, GunDurumu $gun, array $gerekce): void
    {
        $this->atamalar[] = new Atama($tarih, $vardiya, $eczane->bolgeId, $eczane->id, $eczane->grupId, $ozel, $gerekce);
        $gun->ekle($eczane);

        $bolge = $eczane->bolgeId;
        $this->haftalik[$bolge][Takvim::hafta($tarih)][] = $eczane->id;
        $ay = Takvim::ayKimligi($tarih);
        if (! in_array($eczane->id, $this->aylik[$bolge][$ay] ?? [], true)) {
            $this->aylik[$bolge][$ay][] = $eczane->id;
        }
        $this->donem[$bolge][$eczane->id] = ($this->donem[$bolge][$eczane->id] ?? 0) + 1;
        $this->gunleri[$eczane->id][] = self::gunNo($tarih);
        if (! $ozel) {
            // Özel gün nöbeti sayılmaz (raporlarda da sayılmaz): bayram nöbeti cumartesi hakkını yemesin.
            $this->sayaclar->isle($eczane->id, $tarih);
        }
    }

    private function uyar(DateTimeImmutable $tarih, int $bolgeId, string $mesaj): void
    {
        $this->uyarilar[] = sprintf('%s, %s: %s.', $tarih->format('d.m.Y'), $this->bolgeAdi($bolgeId), $mesaj);
    }

    private function bolgeAdi(int $bolgeId): string
    {
        return $this->bolgeAdlari[$bolgeId] ?? "Bölge {$bolgeId}";
    }
}
