<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Sayaclar;

/** Nöbet kayıtları: takvim görünümü, elle değiştirme ve motorun geçmiş sayaçları. */
final class Nobetler
{
    public const TABLO = 'nobet_nobetler';

    public const MOTOR = 'motor';

    public const ELLE = 'elle';

    public const AKTARIM = 'aktarim';

    /** Takvimde bir seferde en çok bu kadar gün (bir yıl ve biraz). */
    private const EN_COK_GUN = 400;

    /** @return array{bolgeler: list<array{id: int, ad: string}>, nobetler: list<array<string, mixed>>, ozel_gunler: list<array<string, mixed>>} */
    public function takvim(string $baslangic, string $bitis): array
    {
        $baslangic = Kayit::tarih('baslangic', $baslangic);
        $bitis = Kayit::tarih('bitis', $bitis);
        $gun = (new DateTimeImmutable($baslangic))->diff(new DateTimeImmutable($bitis));
        if ($gun->invert || $gun->days >= self::EN_COK_GUN) {
            throw ValidationException::field('bitis', 'Bitiş başlangıçtan sonra ve en çok '.self::EN_COK_GUN.' gün sonra olmalı.');
        }

        $nobetler = DB::table(self::TABLO.' as n')
            ->leftJoin(Eczaneler::TABLO.' as e', 'e.id', '=', 'n.eczane_id')
            ->whereBetween('n.tarih', [$baslangic, $bitis])
            ->orderBy('n.tarih')->orderBy('n.id')
            ->get(['n.*', 'e.ad as eczane', 'e.telefon', 'e.adres'])
            ->map(fn ($n) => [
                'id' => (int) $n->id,
                'tarih' => substr((string) $n->tarih, 0, 10),
                'vardiya' => $n->vardiya,
                'bolge_id' => (int) $n->bolge_id,
                'eczane_id' => (int) $n->eczane_id,
                'eczane' => $n->eczane,
                'telefon' => $n->telefon,
                'adres' => $n->adres,
                'grup_id' => $n->grup_id !== null ? (int) $n->grup_id : null,
                'ozel_gun' => (bool) $n->ozel_gun,
                'kaynak' => $n->kaynak,
                'dongu_id' => $n->dongu_id !== null ? (int) $n->dongu_id : null,
                'gerekce' => $n->gerekce ? json_decode($n->gerekce, true) : null,
            ])->all();

        $bolgeler = [];
        foreach ((new Bolgeler)->adlar() as $id => $ad) {
            $bolgeler[] = ['id' => $id, 'ad' => $ad];
        }
        $ozel = array_values(array_filter((new OzelGunler)->liste(), fn ($g) => $g['tarih'] >= $baslangic && $g['tarih'] <= $bitis));

        return ['bolgeler' => $bolgeler, 'nobetler' => $nobetler, 'ozel_gunler' => $ozel];
    }

    /**
     * Herkese açık nöbetçi listesi: "bugün"den (devir saatinden önceyse dün: gece 02:00'de dünün nöbetçisi açıktır)
     * başlayarak `gun` günün nöbetçileri, bölge sırasıyla. Yalnızca kamuya açık bilgiler (ad, telefon, adres, tarif, konum).
     *
     * @return array{bugun: string, devir_saati: string, bolgeler: list<array{id: int, ad: string}>, gunler: list<array<string, mixed>>}
     */
    public function nobetciler(int $gun, string $devirSaati, ?DateTimeImmutable $simdi = null): array
    {
        $simdi ??= new DateTimeImmutable('now');
        $devirSaati = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $devirSaati) ? $devirSaati : '08:30';
        $bugun = $simdi->format('H:i') < $devirSaati ? $simdi->modify('-1 day') : $simdi;
        $gun = max(1, min(31, $gun));
        $baslangic = $bugun->format('Y-m-d');
        $bitis = $bugun->modify('+'.($gun - 1).' days')->format('Y-m-d');

        $bolgeler = [];
        foreach ((new Bolgeler)->adlar() as $id => $ad) {
            $bolgeler[] = ['id' => $id, 'ad' => $ad];
        }
        $sira = array_flip(array_column($bolgeler, 'id'));
        $vardiyaSirasi = ['gunduz' => 0, 'gece' => 1, 'tumgun' => 2];
        $satirlar = DB::table(self::TABLO.' as n')
            ->join(Eczaneler::TABLO.' as e', 'e.id', '=', 'n.eczane_id')
            ->whereBetween('n.tarih', [$baslangic, $bitis])
            ->get(['n.tarih', 'n.vardiya', 'n.bolge_id', 'e.ad', 'e.telefon', 'e.adres', 'e.tarif', 'e.enlem', 'e.boylam'])
            ->sortBy(fn ($n) => [substr((string) $n->tarih, 0, 10), $sira[$n->bolge_id] ?? 999, $vardiyaSirasi[$n->vardiya] ?? 9, $n->ad])
            ->groupBy(fn ($n) => substr((string) $n->tarih, 0, 10));
        $ozel = array_column((new OzelGunler)->liste(), 'ad', 'tarih');

        $gunler = [];
        for ($t = new DateTimeImmutable($baslangic); $t->format('Y-m-d') <= $bitis; $t = $t->modify('+1 day')) {
            $tarih = $t->format('Y-m-d');
            $gunler[] = [
                'tarih' => $tarih,
                'ozel_gun' => $ozel[$tarih] ?? null,
                'nobetler' => ($satirlar[$tarih] ?? collect())->map(fn ($n) => [
                    'bolge_id' => (int) $n->bolge_id,
                    'vardiya' => $n->vardiya,
                    'eczane' => $n->ad,
                    'telefon' => $n->telefon,
                    'adres' => $n->adres,
                    'tarif' => $n->tarif,
                    'enlem' => $n->enlem !== null ? (float) $n->enlem : null,
                    'boylam' => $n->boylam !== null ? (float) $n->boylam : null,
                ])->values()->all(),
            ];
        }

        return ['bugun' => $baslangic, 'devir_saati' => $devirSaati, 'bolgeler' => $bolgeler, 'gunler' => $gunler];
    }

    /**
     * Motorun geçmişi: özel gün dışındaki bütün nöbetler (aktarılan eski kayıtlar dahil) ve eczanelerin devir sayıları.
     * Eski sistemde bu sayılar eczaneler tablosunda ayrıca tutulup elle eşitleniyordu; burada her seferinde yeniden.
     */
    /**
     * Geçmiş nöbet sayaçları. `$baslangic` verilirse yalnızca o günden sonraki kayıtlar sayılır ve eski sistemden gelen
     * devir eklenmez (döngünün "şu tarihten itibaren geçmiş raporları kullan" seçeneği).
     */
    public function sayaclar(bool $devirli = true, ?string $baslangic = null): Sayaclar
    {
        $sayaclar = new Sayaclar($devirli && $baslangic === null ? (new Eczaneler)->devirler() : []);
        $sorgu = DB::table(self::TABLO)->where('ozel_gun', false);
        if ($baslangic !== null) {
            $sorgu->where('tarih', '>=', $baslangic);
        }
        foreach ($sorgu->orderBy('id')->select(['eczane_id', 'tarih'])->cursor() as $n) {
            $sayaclar->isle((int) $n->eczane_id, new DateTimeImmutable(substr((string) $n->tarih, 0, 10)));
        }

        return $sayaclar;
    }

    /**
     * Eczane → aralıktaki nöbet günleri (Y-m-d): motorun R (en az aralık) ve S (en uzun süredir) kuralları için.
     *
     * @return array<int, list<string>>
     */
    public function gunleri(string $baslangic, string $bitis): array
    {
        $gunler = [];
        foreach (DB::table(self::TABLO)->whereBetween('tarih', [$baslangic, $bitis])->orderBy('tarih')->select(['eczane_id', 'tarih'])->cursor() as $n) {
            $gunler[(int) $n->eczane_id][] = substr((string) $n->tarih, 0, 10);
        }

        return $gunler;
    }

    /** @return list<string> aralıkta özel gün dışında nöbeti yazılmış günler (döngü bunları atlar) */
    public function doluGunler(string $baslangic, string $bitis): array
    {
        return DB::table(self::TABLO)->where('ozel_gun', false)->whereBetween('tarih', [$baslangic, $bitis])
            ->distinct()->orderBy('tarih')->pluck('tarih')->map(fn ($t) => substr((string) $t, 0, 10))->values()->all();
    }

    /**
     * Tek nöbetin eczanesini değiştirir (aynı bölgeden; o gün o vardiyada nöbeti olmayan). Kaynak "elle" olur,
     * gerekçeye önceki eczane yazılır.
     *
     * @return array{nobet: array<string, mixed>, onceki: string, yeni: string}
     */
    public function degistir(int $id, mixed $eczaneId): array
    {
        $nobet = Kayit::bul(self::TABLO, $id, 'Nöbet');
        $eczaneId = filter_var($eczaneId, FILTER_VALIDATE_INT);
        $eczane = $eczaneId ? DB::table(Eczaneler::TABLO)->where('id', $eczaneId)->first() : null;
        if (! $eczane) {
            throw ValidationException::field('eczane_id', 'Eczane seçin.');
        }
        if ((int) $nobet->eczane_id === (int) $eczane->id) {
            throw new DomainException('nobet_ayni', 'Nöbet zaten bu eczanede.');
        }
        if ((int) $eczane->bolge_id !== (int) $nobet->bolge_id) {
            throw ValidationException::field('eczane_id', 'Nöbet aynı bölgeden bir eczaneye verilebilir.');
        }
        $ayniGun = DB::table(self::TABLO)->whereDate('tarih', substr((string) $nobet->tarih, 0, 10))
            ->where('vardiya', $nobet->vardiya)->where('eczane_id', $eczane->id)->where('id', '!=', $id)->exists();
        if ($ayniGun) {
            throw ValidationException::field('eczane_id', 'Bu eczane o gün zaten nöbetçi.');
        }
        $onceki = (string) DB::table(Eczaneler::TABLO)->where('id', $nobet->eczane_id)->value('ad');
        DB::table(self::TABLO)->where('id', $id)->update([
            'eczane_id' => (int) $eczane->id,
            'grup_id' => $eczane->grup_id,
            'kaynak' => self::ELLE,
            'gerekce' => json_encode(['elle' => true, 'onceki' => (int) $nobet->eczane_id]),
            'updated_at' => Clock::now(),
        ]);

        return ['nobet' => (array) DB::table(self::TABLO)->where('id', $id)->first(), 'onceki' => $onceki, 'yeni' => (string) $eczane->ad];
    }

    /**
     * Elle nöbet yazar (Nöbet Kayıtları ekranı). Bölge ve grup eczaneden; özel gün tarihten (özel günler tablosu),
     * istemcinin gönderdiğinden değil. Aynı eczane aynı gün aynı vardiyada iki kez yazılmaz.
     *
     * @param  array{eczane_id?: mixed, tarih?: mixed, vardiya?: mixed}  $girdi
     * @return array{id: int, ad: string}
     */
    public function ekle(array $girdi): array
    {
        [$eczane, $tarih, $vardiya] = $this->dogrula($girdi, null);
        $simdi = Clock::now();
        $id = (int) DB::table(self::TABLO)->insertGetId([
            'tarih' => $tarih, 'vardiya' => $vardiya, 'bolge_id' => $eczane->bolge_id, 'eczane_id' => $eczane->id, 'grup_id' => $eczane->grup_id,
            'ozel_gun' => self::ozelGunMu($tarih), 'dongu_id' => null, 'kaynak' => self::ELLE, 'gerekce' => json_encode(['elle' => true]),
            'created_at' => $simdi, 'updated_at' => $simdi,
        ]);

        return ['id' => $id, 'ad' => $tarih.' '.$eczane->ad];
    }

    /**
     * Nöbetin eczanesini, tarihini ya da vardiyasını değiştirir (gönderilmeyen korunur). Başka bölgeden eczaneye de
     * verilebilir (eski ekran böyle): nöbetin bölgesi yeni eczanenin bölgesi olur.
     *
     * @param  array<string, mixed>  $girdi
     * @return array{id: int, ad: string}
     */
    public function guncelle(int $id, array $girdi): array
    {
        $nobet = Kayit::bul(self::TABLO, $id, 'Nöbet');
        $girdi += ['eczane_id' => $nobet->eczane_id, 'tarih' => substr((string) $nobet->tarih, 0, 10), 'vardiya' => $nobet->vardiya];
        [$eczane, $tarih, $vardiya] = $this->dogrula($girdi, $id);
        $gerekce = (int) $nobet->eczane_id !== (int) $eczane->id ? ['elle' => true, 'onceki' => (int) $nobet->eczane_id] : (json_decode((string) $nobet->gerekce, true) ?: ['elle' => true]);
        DB::table(self::TABLO)->where('id', $id)->update([
            'tarih' => $tarih, 'vardiya' => $vardiya, 'bolge_id' => $eczane->bolge_id, 'eczane_id' => $eczane->id, 'grup_id' => $eczane->grup_id,
            'ozel_gun' => self::ozelGunMu($tarih), 'kaynak' => self::ELLE, 'gerekce' => json_encode($gerekce), 'updated_at' => Clock::now(),
        ]);

        return ['id' => $id, 'ad' => $tarih.' '.$eczane->ad];
    }

    public function sil(int $id): string
    {
        $nobet = Kayit::bul(self::TABLO, $id, 'Nöbet');
        DB::table(self::TABLO)->where('id', $id)->delete();

        return substr((string) $nobet->tarih, 0, 10).' '.DB::table(Eczaneler::TABLO)->where('id', $nobet->eczane_id)->value('ad');
    }

    /** Tarih özel günler tablosunda mı (nöbetin özel gün işareti buradan gelir). */
    public static function ozelGunMu(string $tarih): bool
    {
        return DB::table(OzelGunler::TABLO)->whereDate('tarih', $tarih)->exists();
    }

    /** Özel gün eklenince / silinince / tarihi değişince o günün nöbetlerinin işareti. */
    public static function ozelGunIsaretle(string $tarih, bool $ozel): void
    {
        DB::table(self::TABLO)->where('tarih', $tarih)->update(['ozel_gun' => $ozel, 'updated_at' => Clock::now()]);
    }

    /** @return array{0: object, 1: string, 2: string} eczane, tarih, vardiya */
    private function dogrula(array $girdi, ?int $id): array
    {
        $hatalar = [];
        $eczaneId = filter_var($girdi['eczane_id'] ?? null, FILTER_VALIDATE_INT);
        $eczane = $eczaneId ? DB::table(Eczaneler::TABLO)->where('id', $eczaneId)->first() : null;
        if (! $eczane) {
            $hatalar['eczane_id'] = 'Eczane seçin.';
        }
        $tarih = substr(trim((string) ($girdi['tarih'] ?? '')), 0, 10);
        if (! Kayit::tarihMi($tarih)) {
            $hatalar['tarih'] = 'Geçerli bir tarih seçin.';
        }
        $vardiya = (string) ($girdi['vardiya'] ?? 'tumgun') ?: 'tumgun';
        if (! in_array($vardiya, ['tumgun', 'gunduz', 'gece'], true)) {
            $hatalar['vardiya'] = 'Vardiya tüm gün, gündüz ya da gece olmalı.';
        }
        if ($hatalar === [] && DB::table(self::TABLO)->where('tarih', $tarih)->where('vardiya', $vardiya)->where('eczane_id', $eczane->id)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $hatalar['eczane_id'] = 'Bu eczane o gün zaten nöbetçi.';
        }
        if ($hatalar !== []) {
            throw ValidationException::fields($hatalar);
        }

        return [$eczane, $tarih, $vardiya];
    }
}
