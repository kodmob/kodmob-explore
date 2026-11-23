<?php

namespace KodmobTools\Nobet\Motor;

use DateTimeInterface;

/** Tarihten nöbet sayaçlarının anahtarları: gün, ay, mevsim, hafta tipi (eski sistemin sütun adlarıyla). */
final class Takvim
{
    public const GUNLER = [1 => 'pazartesi', 2 => 'sali', 3 => 'carsamba', 4 => 'persembe', 5 => 'cuma', 6 => 'cumartesi', 7 => 'pazar'];

    public const AYLAR = [1 => 'ocak', 2 => 'subat', 3 => 'mart', 4 => 'nisan', 5 => 'mayis', 6 => 'haziran',
        7 => 'temmuz', 8 => 'agustos', 9 => 'eylul', 10 => 'ekim', 11 => 'kasim', 12 => 'aralik'];

    public const MEVSIMLER = ['kis', 'ilkbahar', 'yaz', 'sonbahar'];

    public const HAFTA_TIPLERI = ['haftaici', 'haftasonu'];

    public static function gun(DateTimeInterface $t): string
    {
        return self::GUNLER[(int) $t->format('N')];
    }

    public static function ay(DateTimeInterface $t): string
    {
        return self::AYLAR[(int) $t->format('n')];
    }

    public static function mevsim(DateTimeInterface $t): string
    {
        return match ((int) $t->format('n')) {
            12, 1, 2 => 'kis',
            3, 4, 5 => 'ilkbahar',
            6, 7, 8 => 'yaz',
            default => 'sonbahar',
        };
    }

    public static function haftaTipi(DateTimeInterface $t): string
    {
        return self::haftasonu($t) ? 'haftasonu' : 'haftaici';
    }

    public static function haftasonu(DateTimeInterface $t): bool
    {
        return (int) $t->format('N') >= 6;
    }

    /** ISO hafta (yıl sınırında doğru: 30 Aralık 2024 → 2025-01). */
    public static function hafta(DateTimeInterface $t): string
    {
        return $t->format('o-W');
    }

    public static function ayKimligi(DateTimeInterface $t): string
    {
        return $t->format('Y-m');
    }

    /** @return array{gun: string, ay: string, mevsim: string, hafta: string} */
    public static function anahtarlar(DateTimeInterface $t): array
    {
        return ['gun' => self::gun($t), 'ay' => self::ay($t), 'mevsim' => self::mevsim($t), 'hafta' => self::haftaTipi($t)];
    }
}
