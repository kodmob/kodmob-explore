<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Takvim;
use KodmobTools\Nobet\NobetModule;

/**
 * Eczane yönetim arayüzünün (eski panel; kodmob/eczane/admin) uyum katmanı: arayüz verileri eski tablo ve sütun
 * adlarıyla görür (bolge_title, adi, gun_adi, cumartesiSayisi…), ama genel "tablo getir" yoktur:
 *
 * - Yalnızca listelenen tablolar; süzme yalnızca o tablonun bilinen sütunlarında.
 * - Her yazma asıl servisten geçer (doğrulama, iş kuralları); işlem geçmişi denetleyicide.
 * - Güncelleme eski davranış gibi kısmidir: gönderilmeyen alan korunur.
 * - Eczanenin sayaç sütunları (pazartesiSayisi…) her okumada nöbetlerden ve devirden hesaplanır (eski sistemde elle
 *   eşitleniyordu). Arayüz sayaç yazarsa (yeni eczane formu), nöbetlerden sayılanın üstü devir olur.
 * - `ilce_id` her satırda odanın ilçesidir (her oda ayrı site; ayar nobet.ilce_key).
 */
final class Uyum
{
    public const OKUNUR = ['sehir', 'ilce', 'mahalle', 'sokak_cadde', 'bolgeler', 'gruplar', 'eczaneler', 'ozelgunler', 'raporlar', 'donguler', 'mazeretler', 'mesafeler'];

    public const YAZILIR = ['bolgeler', 'gruplar', 'eczaneler', 'ozelgunler', 'mahalle', 'sokak_cadde', 'raporlar', 'mazeretler'];

    /** Ekranlarda ve işlem geçmişinde tablonun adı. */
    public const ADLAR = ['mazeretler' => 'Mazeret', 'mahalle' => 'Mahalle', 'sokak_cadde' => 'Cadde / sokak', 'bolgeler' => 'Bölge', 'gruplar' => 'Grup', 'eczaneler' => 'Eczane', 'ozelgunler' => 'Özel gün', 'raporlar' => 'Nöbet', 'donguler' => 'Döngü'];

    private ?int $ilce = null;

    public function ilce(): int
    {
        return $this->ilce ??= (int) SettingsService::instance()->get(NobetModule::ILCE_KEY);
    }

    /** Odanın bilgisi (arayüzün GLOBAL_* sabitleri; herkese açık). @return array<string, mixed> */
    public static function oda(): array
    {
        $ayar = SettingsService::instance();
        $ilceKey = (int) $ayar->get(NobetModule::ILCE_KEY);
        $sehirKey = (int) $ayar->get(NobetModule::SEHIR_KEY);
        $ilce = collect(self::konum('ilce'))->firstWhere('ilce_key', $ilceKey);
        $sehir = collect(self::konum('sehir'))->firstWhere('sehir_key', $sehirKey);
        [$enlem, $boylam] = array_pad(array_map('trim', explode(',', (string) $ayar->get(NobetModule::MERKEZ))), 2, '');

        return [
            'ad' => (string) ($ayar->get('site.name') ?: 'Nöbet Yönetimi'),
            'sehir_key' => $sehirKey,
            'ilce_key' => $ilceKey,
            'sehir' => $sehir['sehir_title'] ?? '',
            'ilce' => $ilce['ilce_title'] ?? '',
            'enlem' => $enlem,
            'boylam' => $boylam,
        ];
    }

    /** @return list<array<string, mixed>> il ya da ilçe listesi (pakette JSON; eski sistemin tabloları) */
    private static function konum(string $tablo): array
    {
        static $onbellek = [];

        return $onbellek[$tablo] ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/'.$tablo.'.json'), true) ?: [];
    }

    /**
     * @param  array<string, mixed>  $suzgec  sutun + deger (eşitlik), baslangic + bitis (tarih aralığı, raporlar)
     * @return list<array<string, mixed>>
     */
    public function liste(string $tablo, array $suzgec = []): array
    {
        $satirlar = $this->satirlar($tablo);
        $sutun = (string) ($suzgec['sutun'] ?? '');
        if ($sutun !== '') {
            if (! in_array($sutun, self::sutunlar($tablo), true)) {
                throw ValidationException::field('sutun', "{$tablo} tablosunda {$sutun} sütunu yok.");
            }
            $deger = (string) ($suzgec['deger'] ?? '');
            // Tarih gün düzeyinde: eski MySQL '2026-11-10' ile '2026-11-10 00:00:00'ı eşit sayıyordu, ekranlar ikisini de gönderir.
            $satirlar = in_array($sutun, ['tarih', 'gunu'], true)
                ? array_values(array_filter($satirlar, fn ($s) => substr((string) $s[$sutun], 0, 10) === substr($deger, 0, 10)))
                : array_values(array_filter($satirlar, fn ($s) => (string) $s[$sutun] === $deger));
        }
        if (! empty($suzgec['baslangic']) && ! empty($suzgec['bitis'])) {
            $bas = substr((string) $suzgec['baslangic'], 0, 10);
            $bit = substr((string) $suzgec['bitis'], 0, 10);
            $satirlar = array_values(array_filter($satirlar, fn ($s) => substr((string) ($s['tarih'] ?? ''), 0, 10) >= $bas && substr((string) ($s['tarih'] ?? ''), 0, 10) <= $bit));
        }

        return $satirlar;
    }

    /** Tablonun süzülebilen sütunları (eski adlarıyla; veriden bağımsız: boş tabloda da bilinmeyen sütun reddedilir). @return list<string> */
    public static function sutunlar(string $tablo): array
    {
        return match ($tablo) {
            'sehir' => ['sehir_id', 'sehir_title', 'sehir_key'],
            'ilce' => ['ilce_id', 'ilce_title', 'ilce_key', 'ilce_sehirkey'],
            'mahalle' => ['mahalle_id', 'mahalle_title', 'mahalle_key', 'mahalle_ilcekey', 'mahalle_sehirkey'],
            'sokak_cadde' => ['sokak_cadde_id', 'sokak_cadde_key', 'sokak_cadde_title', 'sokak_cadde_mahallekey', 'sokak_cadde_ilcekey', 'sokak_cadde_sehirkey'],
            'bolgeler' => ['id', 'bolge_title', 'ilce_id'],
            'gruplar' => ['id', 'grup_title', 'grup_eslesme_ids', 'grup_yedek_id', 'grup_ust_id', 'bolge_id', 'ilce_id'],
            'eczaneler' => ['id', 'adi', 'sehir', 'ilce', 'mahalle', 'cadde', 'binano', 'tel', 'adres', 'tarif', 'ilce_id', 'bolge_id', 'grup_id', 'durum', 'renk', 'user_id', 'latlong', 'tarih',
                ...array_map(fn ($a) => $a.'Sayisi', Eczaneler::devirAnahtarlari())],
            'ozelgunler' => ['id', 'adi', 'eczane_ids', 'ilce_id', 'tarih'],
            'raporlar' => ['id', 'eczane_id', 'gun_adi', 'ay_adi', 'sezon_adi', 'gun_tipi', 'nobet_tipi', 'ozel_gun', 'gecmis_gun', 'ilce_id', 'bolge_id', 'grup_id', 'dongu_id', 'tarih', 'kaynak'],
            'mesafeler' => ['id', 'ilce_id', 'eczane_id_1', 'eczane_id_2', 'distance_km', 'updated_at'],
            'mazeretler' => ['id', 'aciklama', 'turu', 'gunu', 'ilce_id', 'tarih', 'eczane_id', 'eczane_adi', 'durum', 'karar_notu', 'karar_tarihi'],
            'donguler' => ['id', 'adi', 'dutiesPerDay', 'method', 'tekrar', 'baslangic', 'bitis', 'ilce_id', 'tarih'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function bul(string $tablo, int $id): array
    {
        foreach ($this->satirlar($tablo) as $satir) {
            if ((int) ($satir['id'] ?? 0) === $id) {
                return $satir;
            }
        }

        throw new DomainException('km_not_found', (self::ADLAR[$tablo] ?? 'Kayıt').' bulunamadı.', 404);
    }

    /** @return list<array<string, mixed>> */
    private function satirlar(string $tablo): array
    {
        return match ($tablo) {
            'sehir' => self::konum('sehir'),
            'ilce' => self::konum('ilce'),
            'mahalle' => $this->mahalleler(),
            'sokak_cadde' => $this->caddeler(),
            'bolgeler' => $this->bolgeler(),
            'gruplar' => $this->gruplar(),
            'eczaneler' => $this->eczaneler(),
            'ozelgunler' => $this->ozelGunler(),
            'raporlar' => $this->raporlar(),
            'donguler' => $this->donguler(),
            'mazeretler' => $this->mazeretler(),
            'mesafeler' => $this->mesafeler(),
            default => throw new DomainException('km_not_found', 'Bu kayıt türü yok.', 404),
        };
    }

    private function sehirKey(): int
    {
        return (int) SettingsService::instance()->get(NobetModule::SEHIR_KEY);
    }

    private function mahalleler(): array
    {
        return array_map(fn ($m) => [
            'mahalle_id' => (int) $m->id, 'mahalle_title' => $m->ad, 'mahalle_key' => (int) $m->anahtar,
            'mahalle_ilcekey' => (int) $m->ilce_key, 'mahalle_sehirkey' => $this->sehirKey(),
        ], (new Adresler)->mahalleler());
    }

    /** `sokak_cadde_key`: eski eczane formu seçeneğin değerini bu (eski tabloda olmayan) alandan okuyordu; kimliğin eşi. */
    private function caddeler(): array
    {
        return array_map(fn ($c) => [
            'sokak_cadde_id' => (int) $c->id, 'sokak_cadde_key' => (int) $c->id, 'sokak_cadde_title' => $c->ad,
            'sokak_cadde_mahallekey' => (int) $c->mahalle_anahtar, 'sokak_cadde_ilcekey' => $this->ilce(), 'sokak_cadde_sehirkey' => $this->sehirKey(),
        ], (new Adresler)->caddeler());
    }

    private function bolgeler(): array
    {
        return DB::table(Bolgeler::TABLO)->orderBy('sira')->orderBy('id')->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'bolge_title' => $b->ad, 'ilce_id' => $this->ilce()])->all();
    }

    private function gruplar(): array
    {
        return DB::table(Gruplar::TABLO)->orderBy('id')->get()->map(fn ($g) => [
            'id' => (int) $g->id,
            'grup_title' => $g->ad,
            'grup_eslesme_ids' => ($ids = Kayit::json($g->eslesme)) === [] ? null : implode(',', $ids),
            'grup_yedek_id' => $g->yedek_id !== null ? (int) $g->yedek_id : null,
            'bolge_id' => $g->bolge_id !== null ? (int) $g->bolge_id : 0,
            'grup_ust_id' => $g->ust_id !== null ? (int) $g->ust_id : null,
            'ilce_id' => $this->ilce(),
        ])->all();
    }

    private function eczaneler(): array
    {
        $sayaclar = (new Nobetler)->sayaclar();

        return DB::table(Eczaneler::TABLO)->orderBy('id')->get()->map(function ($e) use ($sayaclar) {
            $satir = [
                'id' => (int) $e->id,
                'adi' => $e->ad,
                'sehir' => $e->sehir,
                'ilce' => $e->ilce,
                'mahalle' => $e->mahalle,
                'cadde' => $e->cadde,
                'binano' => $e->binano,
                'tel' => (string) $e->telefon,
                'adres' => $e->adres,
                'tarif' => $e->tarif ?? null,
                'ilce_id' => $this->ilce(),
                'bolge_id' => (int) $e->bolge_id,
                'grup_id' => $e->grup_id !== null ? (string) $e->grup_id : '0',
                'durum' => $e->aktif ? '1' : '0',
                'renk' => $e->renk,
                'user_id' => $e->kullanici_id !== null ? (int) $e->kullanici_id : null,
                'latlong' => $e->enlem !== null && $e->boylam !== null ? (float) $e->enlem.', '.(float) $e->boylam : null,
                'tarih' => substr((string) $e->created_at, 0, 19),
            ];
            foreach (Eczaneler::devirAnahtarlari() as $anahtar) {
                $satir[$anahtar.'Sayisi'] = $sayaclar->sayi((int) $e->id, $anahtar);
            }

            return $satir;
        })->all();
    }

    private function ozelGunler(): array
    {
        $kura = [];
        foreach (DB::table(Nobetler::TABLO)->where('ozel_gun', true)->orderBy('id')->get(['tarih', 'eczane_id']) as $n) {
            $kura[substr((string) $n->tarih, 0, 10)][] = (int) $n->eczane_id;
        }

        return DB::table(OzelGunler::TABLO)->orderBy('tarih')->get()->map(fn ($g) => [
            'id' => (int) $g->id,
            'adi' => $g->ad,
            'eczane_ids' => isset($kura[$t = substr((string) $g->tarih, 0, 10)]) ? implode(',', array_unique($kura[$t])) : null,
            'ilce_id' => $this->ilce(),
            'tarih' => $t.' 00:00:00',
        ])->all();
    }

    private function raporlar(): array
    {
        return DB::table(Nobetler::TABLO)->orderBy('tarih')->orderBy('id')->get()->map(function ($n) {
            $t = new DateTimeImmutable(substr((string) $n->tarih, 0, 10));

            return [
                'id' => (int) $n->id,
                'eczane_id' => (int) $n->eczane_id,
                'gun_adi' => Takvim::gun($t),
                'ay_adi' => Takvim::ay($t),
                'sezon_adi' => Takvim::mevsim($t),
                'gun_tipi' => Takvim::haftaTipi($t),
                'nobet_tipi' => $n->vardiya,
                'ozel_gun' => $n->ozel_gun ? 1 : 0,
                'gecmis_gun' => $n->kaynak === Nobetler::AKTARIM ? 1 : 0,
                'ilce_id' => $this->ilce(),
                'bolge_id' => (int) $n->bolge_id,
                'grup_id' => $n->grup_id !== null ? (int) $n->grup_id : null,
                'dongu_id' => $n->dongu_id !== null ? (int) $n->dongu_id : null,
                'tarih' => $t->format('Y-m-d').' 00:00:00',
                'kaynak' => $n->kaynak,
            ];
        })->all();
    }

    /** Eczaneler arası kuş uçuşu mesafe: eski tablo elle / düğmeyle hesaplanıyordu; burada konumdan (motorun kullandığıyla aynı). */
    private function mesafeler(): array
    {
        $eczaneler = array_values(array_filter((new Eczaneler)->etkinler(), fn ($e) => $e->konumuVar()));
        $satirlar = [];
        $simdi = Clock::now();
        foreach ($eczaneler as $i => $a) {
            for ($j = $i + 1; $j < count($eczaneler); $j++) {
                $b = $eczaneler[$j];
                $satirlar[] = ['id' => count($satirlar) + 1, 'ilce_id' => $this->ilce(), 'eczane_id_1' => min($a->id, $b->id), 'eczane_id_2' => max($a->id, $b->id),
                    'distance_km' => round((float) $a->uzaklik($b), 3), 'updated_at' => $simdi];
            }
        }

        return $satirlar;
    }

    private function mazeretler(): array
    {
        $eczaneler = DB::table(Eczaneler::TABLO)->pluck('ad', 'id');

        return array_map(fn ($m) => [
            'id' => (int) $m->id, 'aciklama' => $m->aciklama, 'turu' => $m->tur,
            'gunu' => substr((string) $m->tarih, 0, 10).' 00:00:00', 'ilce_id' => $this->ilce(),
            'tarih' => substr((string) $m->created_at, 0, 19), 'eczane_id' => $m->eczane_id !== null ? (int) $m->eczane_id : null,
            'eczane_adi' => $m->eczane_id !== null ? ($eczaneler[$m->eczane_id] ?? null) : null, 'durum' => $m->durum,
            'karar_notu' => $m->karar_notu ?? null, 'karar_tarihi' => $m->karar_tarihi ? substr((string) $m->karar_tarihi, 0, 16) : null,
        ], (new Mazeretler)->liste());
    }

    private function donguler(): array
    {
        $bolgeSayisi = DB::table(Bolgeler::TABLO)->count();

        return DB::table(Donguler::TABLO)->orderBy('baslangic')->orderBy('id')->get()->map(fn ($d) => [
            'id' => (int) $d->id,
            'adi' => $d->ad,
            'dutiesPerDay' => $bolgeSayisi,
            'method' => $d->kurallar,
            'tekrar' => 1,
            'baslangic' => substr((string) $d->baslangic, 0, 10).' 00:00:00',
            'bitis' => substr((string) $d->bitis, 0, 10).' 00:00:00',
            'ilce_id' => $this->ilce(),
            'tarih' => substr((string) $d->created_at, 0, 19),
        ])->all();
    }

    // ------------------------------------------------------------------ yazma

    /**
     * Ekler (`id` null) ya da günceller (kısmi: gönderilmeyen alan korunur).
     *
     * @param  array<string, mixed>  $govde  eski sütun adlarıyla
     * @return array{id: int, ad: string}
     */
    public function kaydet(string $tablo, ?int $id, array $govde): array
    {
        if (! in_array($tablo, self::YAZILIR, true)) {
            throw new DomainException('nobet_salt_okunur', (self::ADLAR[$tablo] ?? 'Bu kayıt').' bu ekrandan değiştirilemez.', 403);
        }
        // Kısmi güncelleme için mevcut satır; nöbette servis kendisi birleştirir (binlerce satırı dolaşmasın).
        $eski = $id !== null && ! in_array($tablo, ['raporlar', 'mazeretler'], true) ? $this->bul($tablo, $id) : [];
        $yeni = array_merge($eski, $govde);

        return match ($tablo) {
            'bolgeler' => $this->bolgeKaydet($id, $yeni),
            'gruplar' => $this->grupKaydet($id, $yeni),
            'eczaneler' => $this->eczaneKaydet($id, $yeni, $govde),
            'ozelgunler' => $this->ozelGunKaydet($id, $yeni, $govde),
            'mahalle', 'sokak_cadde' => $this->adresKaydet($tablo, $id, $govde),
            'raporlar' => $this->raporKaydet($id, $govde),
            'mazeretler' => (new Mazeretler)->kaydet($id, array_filter([
                'aciklama' => $govde['aciklama'] ?? null, 'tur' => $govde['turu'] ?? null,
                'tarih' => $govde['gunu'] ?? null, 'eczane_id' => $govde['eczane_id'] ?? null,
            ], fn ($v) => $v !== null), request()->user()?->id),
        };
    }

    /** Excel'den toplu eklenebilen tablolar (Nöbet Kayıtları: nöbetler; Eczane Listesi / Eczane Ekle: eczaneler). */
    public const TOPLU = ['raporlar', 'eczaneler'];

    public const TOPLU_EN_COK = 2000;

    /**
     * Excel'den toplu ekleme (eski `saveExcelImportData`): her satır tek tek eklemeyle aynı doğrulamadan geçer; bir
     * satır bile hatalıysa hiçbiri yazılmaz (hepsi ya da hiçbiri), hatalar satır numarasıyla döner. Satırın `_satir`'ı
     * Excel'deki satır numarası (arayüz gönderir; yoksa sıradan).
     *
     * @param  list<array<string, mixed>>  $satirlar
     * @return array{eklenen: int, ad: string}
     */
    public function toplu(string $tablo, array $satirlar): array
    {
        if (! in_array($tablo, self::TOPLU, true)) {
            throw new DomainException('nobet_salt_okunur', (self::ADLAR[$tablo] ?? 'Bu kayıt').' toplu eklenemez.', 403);
        }
        $satirlar = array_values(array_filter($satirlar, 'is_array'));
        if ($satirlar === []) {
            throw ValidationException::field('rows', 'Eklenecek satır yok.');
        }
        if (count($satirlar) > self::TOPLU_EN_COK) {
            throw ValidationException::field('rows', 'Tek seferde en çok '.self::TOPLU_EN_COK.' satır eklenebilir.');
        }

        // Eczane satırlarında bölge / grup Excel'de adıyla da yazılabilir (eski sayfa kimlik bekliyordu).
        if ($tablo === 'eczaneler') {
            $kucuk = static fn ($v) => mb_strtolower(trim((string) $v), 'UTF-8');
            $bolgeler = DB::table(Bolgeler::TABLO)->pluck('id', 'ad')->mapWithKeys(fn ($id, $ad) => [$kucuk($ad) => $id])->all();
            $gruplar = DB::table(Gruplar::TABLO)->pluck('id', 'ad')->mapWithKeys(fn ($id, $ad) => [$kucuk($ad) => $id])->all();
            foreach ($satirlar as &$satir) {
                foreach (['bolge_id' => $bolgeler, 'grup_id' => $gruplar] as $alan => $adlar) {
                    $deger = $satir[$alan] ?? '';
                    if ($deger !== '' && $deger !== null && ! ctype_digit((string) $deger)) {
                        $satir[$alan] = $adlar[$kucuk($deger)] ?? $deger;
                    }
                }
            }
            unset($satir);
        }

        $hatalar = [];
        $eklenen = DB::transaction(function () use ($tablo, $satirlar, &$hatalar) {
            $n = 0;
            foreach ($satirlar as $i => $satir) {
                $no = filter_var($satir['_satir'] ?? null, FILTER_VALIDATE_INT) ?: $i + 2;
                unset($satir['_satir'], $satir['id']);
                try {
                    $this->kaydet($tablo, null, $satir);
                    $n++;
                } catch (DomainException $e) {
                    $alanlar = $e->details()['fields'] ?? [];
                    $hatalar[] = 'Satır '.$no.': '.($alanlar ? implode(' ', array_unique(array_map('strval', $alanlar))) : $e->getMessage());
                }
            }
            if ($hatalar !== []) {
                // Hepsi ya da hiçbiri: işlem geri alınır.
                $ozet = array_slice($hatalar, 0, 20);
                if (count($hatalar) > 20) {
                    $ozet[] = '… ve '.(count($hatalar) - 20).' satır daha';
                }
                throw ValidationException::fields(['rows' => implode("\n", $ozet)], count($hatalar).' satırda hata var; hiçbir satır eklenmedi.');
            }

            return $n;
        });

        return ['eklenen' => $eklenen, 'ad' => (self::ADLAR[$tablo] ?? $tablo).': '.$eklenen.' satır'];
    }

    /** Siler; adını döner (işlem geçmişi). */
    public function sil(string $tablo, int $id): string
    {
        return match ($tablo) {
            'bolgeler' => (new Bolgeler)->sil($id),
            'gruplar' => (new Gruplar)->sil($id),
            'eczaneler' => (new Eczaneler)->sil($id),
            'ozelgunler' => (new OzelGunler)->sil($id),
            'donguler' => (new Donguler)->sil($id)['ad'],
            'raporlar' => (new Nobetler)->sil($id),
            'mazeretler' => (new Mazeretler)->sil($id),
            default => throw new DomainException('nobet_salt_okunur', (self::ADLAR[$tablo] ?? 'Bu kayıt').' bu ekrandan silinemez.', 403),
        };
    }

    /**
     * Nöbet kaydı (Nöbet Kayıtları, Takvim, Özel Günler ekranları). Yalnızca eczane, tarih ve vardiya (nobet_tipi) alınır:
     * bölge / grup eczaneden, özel gün tarihten gelir; sayaç alanları yok sayılır. Eski ekranlarda nöbet kimliği yerine
     * eczane kimliğiyle yapılan hatalı çağrılar (ör. yalnızca ozel_gun ya da sayaç gönderen) böylece hiçbir şey değiştirmez.
     */
    private function raporKaydet(?int $id, array $govde): array
    {
        $girdi = array_filter([
            'eczane_id' => $govde['eczane_id'] ?? null,
            'tarih' => $govde['tarih'] ?? null,
            'vardiya' => $govde['nobet_tipi'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($id === null) {
            return (new Nobetler)->ekle($girdi);
        }
        if ($girdi === []) {
            return ['id' => $id, 'ad' => 'değişiklik yok'];
        }

        return (new Nobetler)->guncelle($id, $girdi);
    }

    /** Adres Ekleme sayfası: yalnızca ekleme (eski sayfada düzenleme / silme yok). */
    private function adresKaydet(string $tablo, ?int $id, array $s): array
    {
        if ($id !== null) {
            throw new DomainException('nobet_salt_okunur', (self::ADLAR[$tablo]).' değiştirilemez; yenisini ekleyin.', 403);
        }
        if ($tablo === 'mahalle') {
            $m = (new Adresler)->mahalleEkle((string) ($s['mahalle_title'] ?? ''), $this->ilce(), isset($s['mahalle_key']) ? (int) $s['mahalle_key'] : null);

            return ['id' => $m['id'], 'ad' => $m['ad']];
        }

        return (new Adresler)->caddeEkle((string) ($s['sokak_cadde_title'] ?? ''), (int) ($s['sokak_cadde_mahallekey'] ?? 0));
    }

    private function bolgeKaydet(?int $id, array $s): array
    {
        $sira = $id !== null ? (int) DB::table(Bolgeler::TABLO)->where('id', $id)->value('sira') : DB::table(Bolgeler::TABLO)->count() + 1;
        $kayit = (new Bolgeler)->kaydet($id, ['ad' => $s['bolge_title'] ?? '', 'sira' => $sira]);

        return ['id' => $kayit['id'], 'ad' => $kayit['ad']];
    }

    private function grupKaydet(?int $id, array $s): array
    {
        $eslesme = $s['grup_eslesme_ids'] ?? [];
        $eslesme = is_array($eslesme) ? $eslesme : array_filter(explode(',', (string) $eslesme), fn ($v) => trim($v) !== '');
        $kayit = (new Gruplar)->kaydet($id, [
            'ad' => $s['grup_title'] ?? '',
            'eslesme' => array_values(array_map('intval', $eslesme)),
            'yedek_id' => $s['grup_yedek_id'] ?? null,
            'bolge_id' => $s['bolge_id'] ?? null,
            'ust_id' => $s['grup_ust_id'] ?? null,
        ]);

        return ['id' => $kayit['id'], 'ad' => $kayit['ad']];
    }

    /** @param array<string, mixed> $govde gönderilen alanlar (sayaç yazıldı mı) */
    private function eczaneKaydet(?int $id, array $s, array $govde): array
    {
        $konum = array_map('trim', explode(',', (string) ($s['latlong'] ?? '')));
        $mevcut = $id !== null ? DB::table(Eczaneler::TABLO)->where('id', $id)->first() : null;
        $girdi = [
            'ad' => $s['adi'] ?? '',
            'eczaci' => $mevcut->eczaci ?? '',
            'telefon' => Aktarim::telefon((string) ($s['tel'] ?? '')) ?? '',
            'sehir' => $s['sehir'] ?? '',
            'ilce' => $s['ilce'] ?? '',
            'mahalle' => $s['mahalle'] ?? '',
            'cadde' => $s['cadde'] ?? '',
            'binano' => $s['binano'] ?? '',
            'tarif' => (string) ($s['tarif'] ?? ''),
            'renk' => $s['renk'] ?? '',
            'bolge_id' => $s['bolge_id'] ?? '',
            'grup_id' => $s['grup_id'] ?? '',
            'aktif' => (string) ($s['durum'] ?? '1') === '1',
            'enlem' => count($konum) === 2 && $konum[0] !== '' ? $konum[0] : '',
            'boylam' => count($konum) === 2 && $konum[1] !== '' ? $konum[1] : '',
            'devir' => $this->devir($id, $govde, $mevcut),
        ];
        $kayit = (new Eczaneler)->kaydet($id, $girdi);

        return ['id' => $kayit['id'], 'ad' => $kayit['ad']];
    }

    /**
     * Arayüz sayaç yazdıysa (eski "nöbet sayıları" alanları) devir = yazılan − nöbetlerden sayılan (en az 0);
     * yazmadıysa mevcut devir korunur.
     *
     * @return array<string, int>
     */
    private function devir(?int $id, array $govde, ?object $mevcut): array
    {
        $onceki = $mevcut && $mevcut->devir ? (json_decode($mevcut->devir, true) ?: []) : [];
        $yazilan = [];
        foreach (Eczaneler::devirAnahtarlari() as $anahtar) {
            if (array_key_exists($anahtar.'Sayisi', $govde) && $govde[$anahtar.'Sayisi'] !== '' && $govde[$anahtar.'Sayisi'] !== null) {
                $yazilan[$anahtar] = (int) $govde[$anahtar.'Sayisi'];
            }
        }
        if ($yazilan === []) {
            return $onceki;
        }
        $sayilan = $id !== null ? (new Nobetler)->sayaclar(false) : null;
        $devir = $onceki;
        foreach ($yazilan as $anahtar => $sayi) {
            $fark = max(0, $sayi - ($sayilan?->sayi($id, $anahtar) ?? 0));
            if ($fark > 0) {
                $devir[$anahtar] = $fark;
            } else {
                unset($devir[$anahtar]);
            }
        }

        return $devir;
    }

    /** Özel gün; `eczane_ids` gönderildiyse o güne kurayla yazılan eczaneler (özel gün nöbetleri) yenilenir. */
    private function ozelGunKaydet(?int $id, array $s, array $govde): array
    {
        $kayit = (new OzelGunler)->kaydet($id, ['tarih' => substr((string) ($s['tarih'] ?? ''), 0, 10), 'ad' => $s['adi'] ?? '']);
        if (array_key_exists('eczane_ids', $govde)) {
            $this->kuraYaz($kayit['tarih'], $govde['eczane_ids']);
        }

        return ['id' => $kayit['id'], 'ad' => $kayit['tarih'].' '.$kayit['ad']];
    }

    /** Özel güne kurayla yazılan eczaneler: o günün özel gün nöbetleri bu listeyle değişir (döngünün yazdıkları dahil). */
    private function kuraYaz(string $tarih, mixed $ids): void
    {
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $eczaneler = DB::table(Eczaneler::TABLO)->whereIn('id', $ids)->get()->keyBy('id');
        if (count($eczaneler) !== count($ids)) {
            throw ValidationException::field('eczane_ids', 'Seçilen eczanelerden biri bulunamadı.');
        }
        DB::transaction(function () use ($tarih, $ids, $eczaneler) {
            DB::table(Nobetler::TABLO)->where('tarih', $tarih)->where('ozel_gun', true)->delete();
            $simdi = Clock::now();
            foreach ($ids as $eczaneId) {
                $e = $eczaneler[$eczaneId];
                DB::table(Nobetler::TABLO)->insert([
                    'tarih' => $tarih, 'vardiya' => 'tumgun', 'bolge_id' => $e->bolge_id, 'eczane_id' => $e->id, 'grup_id' => $e->grup_id,
                    'ozel_gun' => true, 'dongu_id' => null, 'kaynak' => Nobetler::ELLE, 'gerekce' => json_encode(['elle' => true, 'ozel_gun_kurasi' => true]),
                    'created_at' => $simdi, 'updated_at' => $simdi,
                ]);
            }
        });
    }
}
