<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Eczane;
use KodmobTools\Nobet\Motor\Takvim;

/** Eczaneler: bölgesi, grubu, konumu (coğrafi kural ve harita), adres tarifi ve sisteme sonradan katılanın devir sayıları. */
final class Eczaneler
{
    public const TABLO = 'nobet_eczaneler';

    private const KOORDINAT = '/^-?\d{1,3}(\.\d{1,8})?$/';

    private static function sema(): Schema
    {
        return Schema::object([
            'ad' => Field::string('Eczanenin adını yazın.')->max(120),
            'eczaci' => Field::string()->optional()->max(120),
            'telefon' => Field::string()->optional()->max(30)->pattern('/^[0-9 +()\-]+$/'),
            'adres' => Field::string()->optional()->max(300),
            'tarif' => Field::string('Adres tarifi en çok 300 karakter olabilir.')->optional()->max(300),
            'enlem' => Field::string('Enlem -90 ile 90 arasında bir sayı olmalı (ör. 37.0755).')->optional()->pattern(self::KOORDINAT),
            'boylam' => Field::string('Boylam -180 ile 180 arasında bir sayı olmalı (ör. 36.2285).')->optional()->pattern(self::KOORDINAT),
            'bolge_id' => Field::integer('Bölge seçin.')->min(1),
            'grup_id' => Field::integer()->optional()->min(1)->default(null),
            'aktif' => Field::boolean()->optional()->default(true),
            // Eski arayüzün parçalı adresi ve takvim rengi (2026_11_10 migration).
            'sehir' => Field::string()->optional()->max(80),
            'ilce' => Field::string()->optional()->max(80),
            'mahalle' => Field::string()->optional()->max(150),
            'cadde' => Field::string()->optional()->max(150),
            'binano' => Field::string()->optional()->max(30),
            'renk' => Field::string('Renk #RRGGBB biçiminde olmalı.')->optional()->pattern('/^#[0-9a-fA-F]{6}$/'),
        ]);
    }

    /** Parçalı adres alanları (eski arayüz). */
    public const ADRES_PARCALARI = ['sehir', 'ilce', 'mahalle', 'cadde', 'binano'];

    /** Devir sayılarında geçerli anahtarlar. */
    public static function devirAnahtarlari(): array
    {
        return [...array_values(Takvim::GUNLER), ...array_values(Takvim::AYLAR), ...Takvim::MEVSIMLER, ...Takvim::HAFTA_TIPLERI];
    }

    /** @return list<array<string, mixed>> */
    public function liste(): array
    {
        $bolgeler = DB::table(Bolgeler::TABLO)->pluck('ad', 'id');
        $gruplar = DB::table(Gruplar::TABLO)->pluck('ad', 'id');

        return DB::table(self::TABLO)->orderBy('ad')->get()->map(fn ($e) => $this->satir($e) + [
            'bolge' => $bolgeler[$e->bolge_id] ?? null,
            'grup' => $e->grup_id ? ($gruplar[$e->grup_id] ?? null) : null,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function satir(object $e): array
    {
        $devir = $e->devir ? json_decode($e->devir, true) : [];

        return [
            'id' => (int) $e->id,
            'ad' => $e->ad,
            'eczaci' => $e->eczaci,
            'telefon' => $e->telefon,
            'adres' => $e->adres,
            'tarif' => $e->tarif ?? null,
            'enlem' => $e->enlem !== null ? (float) $e->enlem : null,
            'boylam' => $e->boylam !== null ? (float) $e->boylam : null,
            'bolge_id' => (int) $e->bolge_id,
            'grup_id' => $e->grup_id !== null ? (int) $e->grup_id : null,
            'aktif' => (bool) $e->aktif,
            'devir' => is_array($devir) ? $devir : [],
            'sehir' => $e->sehir ?? null,
            'ilce' => $e->ilce ?? null,
            'mahalle' => $e->mahalle ?? null,
            'cadde' => $e->cadde ?? null,
            'binano' => $e->binano ?? null,
            'renk' => $e->renk ?? null,
        ];
    }

    /**
     * Döngüye katılanlar, bölgelerin sırasıyla (bölge içinde kimlik sırası).
     *
     * @return list<Eczane>
     */
    public function etkinler(): array
    {
        return DB::table(self::TABLO.' as e')
            ->join(Bolgeler::TABLO.' as b', 'b.id', '=', 'e.bolge_id')
            ->where('e.aktif', true)
            ->orderBy('b.sira')->orderBy('b.id')->orderBy('e.id')
            ->get(['e.id', 'e.bolge_id', 'e.grup_id', 'e.enlem', 'e.boylam'])
            ->map(fn ($e) => new Eczane((int) $e->id, (int) $e->bolge_id, $e->grup_id !== null ? (int) $e->grup_id : null,
                $e->enlem !== null ? (float) $e->enlem : null, $e->boylam !== null ? (float) $e->boylam : null))
            ->all();
    }

    /** @return array<int, array<string, int>> eczane → devir sayıları */
    public function devirler(): array
    {
        $devirler = [];
        foreach (DB::table(self::TABLO)->whereNotNull('devir')->get(['id', 'devir']) as $e) {
            $sayilar = json_decode((string) $e->devir, true);
            if (is_array($sayilar) && $sayilar !== []) {
                $devirler[(int) $e->id] = array_map('intval', $sayilar);
            }
        }

        return $devirler;
    }

    /** @return array<string, mixed> */
    public function kaydet(?int $id, array $girdi): array
    {
        [$deger, $hatalar] = self::sema()->check(Kayit::bosKimlikler($girdi, ['grup_id']));
        $deger['grup_id'] = Kayit::bosIse($deger['grup_id']);
        if ($id !== null) {
            Kayit::bul(self::TABLO, $id, 'Eczane');
        }
        if (! isset($hatalar['bolge_id']) && ! DB::table(Bolgeler::TABLO)->where('id', $deger['bolge_id'])->exists()) {
            $hatalar['bolge_id'] = 'Bölge seçin.';
        }
        if (! isset($hatalar['grup_id']) && $deger['grup_id'] !== null && ! DB::table(Gruplar::TABLO)->where('id', $deger['grup_id'])->exists()) {
            $hatalar['grup_id'] = 'Grup bulunamadı.';
        }
        foreach (['enlem' => 90, 'boylam' => 180] as $alan => $sinir) {
            if (! isset($hatalar[$alan]) && $deger[$alan] !== '' && abs((float) $deger[$alan]) > $sinir) {
                $hatalar[$alan] = $alan === 'enlem' ? 'Enlem -90 ile 90 arasında olmalı.' : 'Boylam -180 ile 180 arasında olmalı.';
            }
        }
        if (($deger['enlem'] === '') !== ($deger['boylam'] === '') && ! isset($hatalar['enlem'], $hatalar['boylam'])) {
            $hatalar[$deger['enlem'] === '' ? 'enlem' : 'boylam'] = 'Konum için enlem ve boylamın ikisi de gerekli.';
        }
        $devir = $this->devir($girdi['devir'] ?? null, $hatalar);
        Schema::fail($hatalar);

        // Adres yazılmadıysa parçalarından (mahalle cadde binano).
        $adres = $deger['adres'] ?: trim(implode(' ', array_filter([$deger['mahalle'], $deger['cadde'], $deger['binano']])));
        $satir = [
            'ad' => $deger['ad'],
            'eczaci' => $deger['eczaci'] ?: null,
            'telefon' => $deger['telefon'] ?: null,
            'adres' => $adres !== '' ? mb_substr($adres, 0, 300) : null,
            // Tarif gönderilmediyse (ör. panelin eski formu) mevcut korunur.
            'tarif' => array_key_exists('tarif', $girdi) ? ($deger['tarif'] ?: null) : ($id !== null ? DB::table(self::TABLO)->where('id', $id)->value('tarif') : null),
            'sehir' => $deger['sehir'] ?: null,
            'ilce' => $deger['ilce'] ?: null,
            'mahalle' => $deger['mahalle'] ?: null,
            'cadde' => $deger['cadde'] ?: null,
            'binano' => $deger['binano'] ?: null,
            'renk' => $deger['renk'] ? strtoupper($deger['renk']) : null,
            'enlem' => $deger['enlem'] !== '' ? $deger['enlem'] : null,
            'boylam' => $deger['boylam'] !== '' ? $deger['boylam'] : null,
            'bolge_id' => $deger['bolge_id'],
            'grup_id' => $deger['grup_id'],
            'aktif' => $deger['aktif'],
            'devir' => $devir === [] ? null : json_encode($devir),
            'updated_at' => Clock::now(),
        ];
        if ($id === null) {
            $id = (int) DB::table(self::TABLO)->insertGetId($satir + ['created_at' => Clock::now()]);
        } else {
            DB::table(self::TABLO)->where('id', $id)->update($satir);
        }

        return $this->satir(DB::table(self::TABLO)->where('id', $id)->first());
    }

    /**
     * Devir sayıları: bilinen anahtar → 0..9999; sıfırlar atılır.
     *
     * @param  array<string, string>  $hatalar
     * @return array<string, int>
     */
    private function devir(mixed $girdi, array &$hatalar): array
    {
        if ($girdi === null || $girdi === []) {
            return [];
        }
        if (! is_array($girdi)) {
            $hatalar['devir'] = 'Devir sayıları geçersiz.';

            return [];
        }
        $gecerli = array_flip(self::devirAnahtarlari());
        $devir = [];
        foreach ($girdi as $anahtar => $sayi) {
            $sayi = filter_var($sayi, FILTER_VALIDATE_INT);
            if (! isset($gecerli[$anahtar]) || $sayi === false || $sayi < 0 || $sayi > 9999) {
                $hatalar['devir'] = 'Devir sayıları 0 ile 9999 arasında tam sayı olmalı.';

                return [];
            }
            if ($sayi > 0) {
                $devir[$anahtar] = $sayi;
            }
        }

        return $devir;
    }

    /** Nöbeti olan eczane silinmez (geçmiş raporlar bozulmasın): pasifleştirilir. */
    public function sil(int $id): string
    {
        $eczane = Kayit::bul(self::TABLO, $id, 'Eczane');
        if (DB::table(Nobetler::TABLO)->where('eczane_id', $id)->exists()) {
            throw new DomainException('nobet_eczane_nobetli', 'Bu eczanenin nöbet kayıtları var; silmek yerine pasif yapın (döngülere girmez, geçmişi kalır).');
        }
        DB::table(self::TABLO)->where('id', $id)->delete();

        return (string) $eczane->ad;
    }
}
