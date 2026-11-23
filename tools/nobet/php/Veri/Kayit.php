<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Kayıt servislerinin ortak yardımcıları. */
final class Kayit
{
    /** Y-m-d ve gerçek bir gün mü (2026-02-30 değil). */
    public static function tarihMi(string $deger): bool
    {
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $deger);

        return $t !== false && $t->format('Y-m-d') === $deger;
    }

    public static function tarih(string $alan, mixed $deger): string
    {
        $deger = is_string($deger) ? substr(trim($deger), 0, 10) : '';
        if (! self::tarihMi($deger)) {
            throw ValidationException::field($alan, 'Geçerli bir tarih seçin.');
        }

        return $deger;
    }

    /**
     * Seçimi boş bırakılabilen kimlik alanları: '', null, 0 → alan yok (Field::integer boş metni hata sayar,
     * yokken 0 verir); doğrulamadan sonra `bosIse()` ile null'a döner.
     *
     * @param  array<string, mixed>  $girdi
     * @param  list<string>  $alanlar
     * @return array<string, mixed>
     */
    public static function bosKimlikler(array $girdi, array $alanlar): array
    {
        foreach ($alanlar as $alan) {
            if (array_key_exists($alan, $girdi) && in_array($girdi[$alan], ['', null, 0, '0'], true)) {
                unset($girdi[$alan]);
            }
        }

        return $girdi;
    }

    public static function bosIse(mixed $deger): ?int
    {
        return is_int($deger) && $deger > 0 ? $deger : null;
    }

    /** Kayıt yoksa 404. */
    public static function bul(string $tablo, int $id, string $ne): object
    {
        return DB::table($tablo)->where('id', $id)->first()
            ?? throw new DomainException('km_not_found', "{$ne} bulunamadı.", 404);
    }

    /** @return list<int> */
    public static function json(?string $deger): array
    {
        $liste = $deger ? json_decode($deger, true) : [];

        return is_array($liste) ? array_values(array_map('intval', $liste)) : [];
    }
}
