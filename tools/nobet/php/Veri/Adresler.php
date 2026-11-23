<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Odanın ilçesinin mahalle ve caddeleri (eczane adres formu, Adres Ekleme sayfası). */
final class Adresler
{
    public const MAHALLELER = 'nobet_mahalleler';

    public const CADDELER = 'nobet_caddeler';

    /** @return list<object> */
    public function mahalleler(): array
    {
        return DB::table(self::MAHALLELER)->orderBy('ad')->get()->all();
    }

    /** @return list<object> */
    public function caddeler(): array
    {
        return DB::table(self::CADDELER)->orderBy('ad')->get()->all();
    }

    /**
     * Mahalle ekler. `anahtar` verilmezse (ya da varsa) yenisi üretilir; ad mahalle başına benzersiz değil ilçe başına.
     *
     * @return array{id: int, ad: string, anahtar: int}
     */
    public function mahalleEkle(string $ad, int $ilceKey, ?int $anahtar = null): array
    {
        $ad = Schema::object(['ad' => Field::string('Mahallenin adını yazın.')->max(150)])->validate(['ad' => $ad])['ad'];
        if (DB::table(self::MAHALLELER)->where('ilce_key', $ilceKey)->where('ad', $ad)->exists()) {
            Schema::fail(['ad' => 'Bu mahalle zaten var.']);
        }
        if (! $anahtar || DB::table(self::MAHALLELER)->where('anahtar', $anahtar)->exists()) {
            $anahtar = $this->yeniAnahtar();
        }
        $id = (int) DB::table(self::MAHALLELER)->insertGetId(['ad' => $ad, 'anahtar' => $anahtar, 'ilce_key' => $ilceKey, 'created_at' => Clock::now()]);

        return ['id' => $id, 'ad' => $ad, 'anahtar' => $anahtar];
    }

    /** @return array{id: int, ad: string} */
    public function caddeEkle(string $ad, int $mahalleAnahtar): array
    {
        $ad = Schema::object(['ad' => Field::string('Caddenin / sokağın adını yazın.')->max(150)])->validate(['ad' => $ad])['ad'];
        if (! DB::table(self::MAHALLELER)->where('anahtar', $mahalleAnahtar)->exists()) {
            Schema::fail(['sokak_cadde_mahallekey' => 'Mahalle seçin.']);
        }
        if (DB::table(self::CADDELER)->where('mahalle_anahtar', $mahalleAnahtar)->where('ad', $ad)->exists()) {
            Schema::fail(['ad' => 'Bu cadde / sokak bu mahallede zaten var.']);
        }
        $id = (int) DB::table(self::CADDELER)->insertGetId(['ad' => $ad, 'mahalle_anahtar' => $mahalleAnahtar, 'created_at' => Clock::now()]);

        return ['id' => $id, 'ad' => $ad];
    }

    /** Eski sistemin anahtarlarıyla çakışmayan yeni mahalle anahtarı (en büyüğün bir fazlası, en az 900000). */
    private function yeniAnahtar(): int
    {
        return max(900000, (int) DB::table(self::MAHALLELER)->max('anahtar') + 1);
    }
}
