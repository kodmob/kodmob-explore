<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Atama;
use KodmobTools\Nobet\Motor\DonguIstegi;
use KodmobTools\Nobet\Motor\Eczane;
use KodmobTools\Nobet\Motor\Kural;
use KodmobTools\Nobet\Motor\MotorHatasi;
use KodmobTools\Nobet\Motor\NobetMotoru;

/**
 * Döngüler: veritabanından motorun girdisini kurar (etkin eczaneler, gruplar, geçmiş sayaçlar, özel günler, önceden
 * yazılmış günler), motoru çalıştırır, önizler ya da kaydeder.
 */
final class Donguler
{
    public const TABLO = 'nobet_donguler';

    public const EN_COK_GUN = 366;

    /** Hatırlatma eşikleri: son nöbet gününe bu kadar gün kala (her biri bir kez). */
    public const HATIRLATMA_GUNLERI = [30, 7];

    /**
     * Günlük görev: yazılmış son nöbet gününe 30 (sonra 7) gün kaldıysa nöbeti yönetenlere bildirim (Bildirim::donguBitiyor).
     * Her eşik aynı son gün için bir kez; liste uzatılınca (yeni son gün) yeniden başlar. Liste zaten bittiyse susar.
     *
     * @return ?array{son_gun: string, kalan: int, esik: int} gönderildiyse
     */
    public function hatirlat(?string $bugun = null): ?array
    {
        $son = DB::table(Nobetler::TABLO)->max('tarih');
        if ($son === null) {
            return null;
        }
        $son = substr((string) $son, 0, 10);
        $bugun ??= substr(Clock::now(), 0, 10);
        $kalan = (int) (new DateTimeImmutable($bugun))->diff(new DateTimeImmutable($son))->format('%r%a');
        $esikler = array_filter(self::HATIRLATMA_GUNLERI, static fn (int $g) => $kalan >= 0 && $kalan <= $g);
        if ($esikler === []) {
            return null;
        }
        $esik = min($esikler);
        $gonderildi = DB::table('notifications')->where('type', 'nobet.dongu_bitiyor')
            ->where(static function ($q) use ($son, $esik) {
                foreach (self::HATIRLATMA_GUNLERI as $g) {
                    if ($g <= $esik) {
                        $q->orWhere('data', 'like', '%"son_gun":"'.$son.'","esik":'.$g.'}%');
                    }
                }
            })->exists();
        if ($gonderildi) {
            return null;
        }
        Bildirim::donguBitiyor($son, $kalan, $esik);

        return ['son_gun' => $son, 'kalan' => $kalan, 'esik' => $esik];
    }

    /** @return list<array<string, mixed>> yeniden eskiye */
    public function liste(): array
    {
        return DB::table(self::TABLO)->orderByDesc('baslangic')->orderByDesc('id')->get()->map(fn ($d) => [
            'id' => (int) $d->id,
            'ad' => $d->ad,
            'baslangic' => substr((string) $d->baslangic, 0, 10),
            'bitis' => substr((string) $d->bitis, 0, 10),
            'kurallar' => $d->kurallar,
            'ayarlar' => $d->ayarlar ? json_decode($d->ayarlar, true) : [],
            'tohum' => $d->tohum,
            'uyarilar' => $d->uyarilar ? json_decode($d->uyarilar, true) : [],
            'atama_sayisi' => (int) $d->atama_sayisi,
            'created_at' => Clock::atom($d->created_at),
        ])->all();
    }

    /**
     * Döngüyü kurar. `kaydet` false ise yalnızca önizler (hiçbir şey yazılmaz).
     *
     * @return array{dongu: array<string, mixed>|null, atama_sayisi: int, uyarilar: list<string>, atlanan_gunler: list<string>, tohum: string, atamalar?: list<array<string, mixed>>}
     */
    public function olustur(array $girdi, ?int $kullanici, bool $kaydet): array
    {
        [$istek, $ayarlar] = $this->istek($girdi);
        try {
            $sonuc = (new NobetMotoru)->olustur($istek, (new Bolgeler)->adlar());
        } catch (MotorHatasi $e) {
            throw new DomainException('nobet_motor', $e->getMessage());
        }
        $baslangic = $istek->baslangic->format('Y-m-d');
        $bitis = $istek->bitis->format('Y-m-d');
        $ozet = [
            'atama_sayisi' => count($sonuc->atamalar),
            'uyarilar' => $sonuc->uyarilar,
            'atlanan_gunler' => $istek->doluGunler,
            'tohum' => (string) $sonuc->tohum,
        ];
        if (! $kaydet) {
            return ['dongu' => null, 'atamalar' => array_map(fn (Atama $a) => $a->dizi(), $sonuc->sirali())] + $ozet;
        }

        $id = DB::transaction(function () use ($istek, $sonuc, $ayarlar, $kullanici, $baslangic, $bitis) {
            $id = (int) DB::table(self::TABLO)->insertGetId([
                'ad' => sprintf('Döngü %s – %s', $istek->baslangic->format('d.m.Y'), $istek->bitis->format('d.m.Y')),
                'baslangic' => $baslangic,
                'bitis' => $bitis,
                'kurallar' => implode(',', array_map(fn (Kural $k) => $k->value, $istek->kurallar)),
                'ayarlar' => json_encode($ayarlar),
                'tohum' => (string) $sonuc->tohum,
                'uyarilar' => json_encode($sonuc->uyarilar, JSON_UNESCAPED_UNICODE),
                'atama_sayisi' => count($sonuc->atamalar),
                'olusturan_id' => $kullanici,
                'created_at' => Clock::now(),
            ]);
            $simdi = Clock::now();
            $gercekGrup = DB::table(Eczaneler::TABLO)->whereNotNull('grup_id')->pluck('grup_id', 'id')->map(fn ($g) => (int) $g)->all();
            $satirlar = array_map(fn (Atama $a) => [
                'tarih' => $a->tarih->format('Y-m-d'),
                'vardiya' => $a->vardiya,
                'bolge_id' => $a->bolgeId,
                'eczane_id' => $a->eczaneId,
                'grup_id' => $gercekGrup[$a->eczaneId] ?? $a->grupId, // motor kök grubu gördü; kayıtta eczanenin kendi grubu
                'ozel_gun' => $a->ozelGun,
                'dongu_id' => $id,
                'kaynak' => Nobetler::MOTOR,
                'gerekce' => json_encode($a->gerekce),
                'created_at' => $simdi,
                'updated_at' => $simdi,
            ], $sonuc->sirali());
            foreach (array_chunk($satirlar, 200) as $parca) {
                DB::table(Nobetler::TABLO)->insert($parca);
            }

            return $id;
        });

        return ['dongu' => $this->bul($id)] + $ozet;
    }

    /** Döngüyü ve onun yazdığı bütün nöbetleri (elle değiştirilenler dahil) siler. */
    public function sil(int $id): array
    {
        $dongu = Kayit::bul(self::TABLO, $id, 'Döngü');
        $silinen = DB::transaction(function () use ($id) {
            $n = DB::table(Nobetler::TABLO)->where('dongu_id', $id)->delete();
            DB::table(self::TABLO)->where('id', $id)->delete();

            return $n;
        });

        return ['ad' => (string) $dongu->ad, 'silinen_nobet' => $silinen];
    }

    /** @return array<string, mixed> */
    private function bul(int $id): array
    {
        foreach ($this->liste() as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }

        throw new DomainException('km_not_found', 'Döngü bulunamadı.', 404);
    }

    /**
     * Girdiyi doğrular, motorun isteğini kurar.
     *
     * @return array{0: DonguIstegi, 1: array<string, mixed>} istek ve döngüye kaydedilecek ayarlar
     */
    private function istek(array $girdi): array
    {
        $hatalar = [];
        $baslangic = substr(trim((string) ($girdi['baslangic'] ?? '')), 0, 10);
        if (! Kayit::tarihMi($baslangic)) {
            $hatalar['baslangic'] = 'Başlangıç tarihini seçin.';
        }
        $gun = filter_var($girdi['gun'] ?? 90, FILTER_VALIDATE_INT);
        if ($gun === false || $gun < 1 || $gun > self::EN_COK_GUN) {
            $hatalar['gun'] = 'Gün sayısı 1 ile '.self::EN_COK_GUN.' arasında olmalı.';
        }
        $kurallar = [];
        try {
            $kurallar = Kural::listeden(is_array($girdi['kurallar'] ?? null) ? $girdi['kurallar'] : (string) ($girdi['kurallar'] ?? ''));
        } catch (MotorHatasi $e) {
            $hatalar['kurallar'] = $e->getMessage();
        }

        $eczaneler = DB::table(Eczaneler::TABLO)->pluck('bolge_id', 'id')->map(fn ($b) => (int) $b)->all();
        $gruplar = DB::table(Gruplar::TABLO)->pluck('id')->map(fn ($g) => (int) $g)->all();
        $bolgeler = DB::table(Bolgeler::TABLO)->pluck('id')->map(fn ($b) => (int) $b)->all();

        $ikili = $this->ciftler($girdi['ikili_engeller'] ?? [], array_keys($eczaneler), 'ikili_engeller', 'eczane', $hatalar);
        $grupEngelleri = $this->ciftler($girdi['grup_engelleri'] ?? [], $gruplar, 'grup_engelleri', 'grup', $hatalar);
        $tarihEngelleri = [];
        foreach ((array) ($girdi['tarih_engelleri'] ?? []) as $engel) {
            $eczane = filter_var($engel['eczane'] ?? null, FILTER_VALIDATE_INT);
            $bas = substr((string) ($engel['baslangic'] ?? ''), 0, 10);
            $bit = substr((string) ($engel['bitis'] ?? ''), 0, 10);
            if (! $eczane || ! isset($eczaneler[$eczane]) || ! Kayit::tarihMi($bas) || ! Kayit::tarihMi($bit) || $bit < $bas) {
                $hatalar['tarih_engelleri'] = 'Tarih engellerinde eczane ve geçerli bir aralık (başlangıç ≤ bitiş) seçin.';

                continue;
            }
            $tarihEngelleri[] = ['eczane' => $eczane, 'baslangic' => $bas, 'bitis' => $bit];
        }
        $gunduz = [];
        foreach ((array) ($girdi['cumartesi_gunduz'] ?? []) as $bolge => $sayi) {
            $sayi = filter_var($sayi, FILTER_VALIDATE_INT);
            if (! in_array((int) $bolge, $bolgeler, true) || $sayi === false || $sayi < 0 || $sayi > 10) {
                $hatalar['cumartesi_gunduz'] = 'Cumartesi gündüz nöbetçi sayısı bölge başına 0 ile 10 arasında olmalı.';

                continue;
            }
            $gunduz[(int) $bolge] = $sayi;
        }
        $aralik = filter_var($girdi['en_az_aralik'] ?? 7, FILTER_VALIDATE_INT);
        if ($aralik === false || $aralik < 1 || $aralik > 60) {
            $hatalar['en_az_aralik'] = 'İki nöbet arası en az gün 1 ile 60 arasında olmalı.';
        }
        $sayacBaslangic = substr(trim((string) ($girdi['sayac_baslangic'] ?? '')), 0, 10);
        if ($sayacBaslangic !== '' && ! Kayit::tarihMi($sayacBaslangic)) {
            $hatalar['sayac_baslangic'] = 'Geçmiş raporların başlangıç tarihi geçerli bir tarih olmalı.';
        } elseif ($sayacBaslangic !== '' && Kayit::tarihMi($baslangic) && $sayacBaslangic > $baslangic) {
            $hatalar['sayac_baslangic'] = 'Geçmiş raporların başlangıcı döngünün başlangıcından sonra olamaz.';
        }
        $tohum = $girdi['tohum'] ?? null;
        if ($tohum !== null && $tohum !== '' && (filter_var($tohum, FILTER_VALIDATE_INT) === false || (int) $tohum < 1)) {
            $hatalar['tohum'] = 'Tohum pozitif bir tam sayı olmalı.';
        }
        Schema::fail($hatalar);

        $etkinler = (new Eczaneler)->etkinler();
        if ($etkinler === []) {
            throw new DomainException('nobet_eczane_yok', 'Döngüye katılacak etkin eczane yok: önce bölge ve eczane ekleyin.');
        }
        // Alt grup üst grubuyla aynı sayılır: motor yalnızca kök grupları görür (eczanenin grubu, I çiftleri, J eşleri).
        $kok = (new Gruplar)->kokler();
        $kokune = fn (?int $g) => $g !== null ? ($kok[$g] ?? $g) : null;
        $etkinler = array_map(fn (Eczane $e) => new Eczane($e->id, $e->bolgeId, $kokune($e->grupId), $e->enlem, $e->boylam), $etkinler);
        $motorGrupEngelleri = [];
        foreach ($grupEngelleri as [$bir, $iki]) {
            [$bir, $iki] = [(int) $kokune($bir), (int) $kokune($iki)];
            if ($bir !== $iki) {
                $motorGrupEngelleri[min($bir, $iki).'-'.max($bir, $iki)] = [min($bir, $iki), max($bir, $iki)];
            }
        }
        $motorGruplari = [];
        foreach ((new Gruplar)->motorIcin() as $g => $tanim) {
            if (($kok[$g] ?? $g) === $g) {
                $motorGruplari[$g] = [
                    'eslesme' => array_values(array_unique(array_filter(array_map($kokune, $tanim['eslesme']), fn ($e) => $e !== $g))),
                    'yedek' => $kokune($tanim['yedek']),
                ];
            }
        }
        $bas = new DateTimeImmutable($baslangic);
        $bit = $bas->modify('+'.($gun - 1).' days');
        $nobetler = new Nobetler;
        $ayarlar = ['gun' => $gun, 'ikili_engeller' => $ikili, 'tarih_engelleri' => $tarihEngelleri, 'grup_engelleri' => $grupEngelleri, 'cumartesi_gunduz' => $gunduz,
            'en_az_aralik' => $aralik, 'sayac_baslangic' => $sayacBaslangic !== '' ? $sayacBaslangic : null];

        return [new DonguIstegi(
            baslangic: $bas,
            bitis: $bit,
            eczaneler: $etkinler,
            kurallar: $kurallar,
            sayaclar: $nobetler->sayaclar(baslangic: $sayacBaslangic !== '' ? $sayacBaslangic : null),
            gruplar: $motorGruplari,
            ozelGunler: (new OzelGunler)->aralikta($bas->format('Y-m-d'), $bit->format('Y-m-d')),
            doluGunler: $nobetler->doluGunler($bas->format('Y-m-d'), $bit->format('Y-m-d')),
            ikiliEngeller: $ikili,
            tarihEngelleri: $tarihEngelleri,
            grupEngelleri: array_values($motorGrupEngelleri),
            cumartesiGunduzSayisi: $gunduz,
            tohum: $tohum !== null && $tohum !== '' ? (int) $tohum : null,
            // R ve S için döngünün çevresindeki nöbetler (bir yıl geriye; döngü aralığındaki önceden yazılmış günler dahil).
            bilinenNobetler: $nobetler->gunleri($bas->modify('-366 days')->format('Y-m-d'), $bit->modify('+'.$aralik.' days')->format('Y-m-d')),
            enAzAralikGun: $aralik,
        ), $ayarlar];
    }

    /**
     * [[a, b], …] çiftleri: iki farklı, var olan kimlik.
     *
     * @param  list<int>  $gecerli
     * @param  array<string, string>  $hatalar
     * @return list<array{0: int, 1: int}>
     */
    private function ciftler(mixed $girdi, array $gecerli, string $alan, string $ne, array &$hatalar): array
    {
        $ciftler = [];
        foreach ((array) $girdi as $cift) {
            $a = filter_var($cift[0] ?? null, FILTER_VALIDATE_INT);
            $b = filter_var($cift[1] ?? null, FILTER_VALIDATE_INT);
            if (! $a || ! $b || $a === $b || ! in_array($a, $gecerli, true) || ! in_array($b, $gecerli, true)) {
                $hatalar[$alan] = "Her satırda iki farklı {$ne} seçin.";

                continue;
            }
            $ciftler[] = [$a, $b];
        }

        return $ciftler;
    }
}
