<?php

namespace KodmobTools\Nobet\Motor;

use DateTimeInterface;

/**
 * Eczane başına geçmiş nöbet sayıları: gün adı (pazartesi…), ay (ocak…), mevsim (kis…) ve hafta tipi (haftaici,
 * haftasonu). Seçim kuralları (B, C, D, E) "en az tutan"ı buradan bulur. Özel gün nöbetleri sayılmaz.
 *
 * Eski sistemde bu sayılar eczaneler tablosunda ayrıca tutulup elle eşitleniyordu; burada her döngüde geçmiş
 * nöbetlerden (ve eczanenin devir sayılarından) yeniden kurulur, bayatlamaz.
 */
final class Sayaclar
{
    /** @var array<int, array<string, int>> */
    private array $sayilar = [];

    /** @param array<int, array<string, int>> $baslangic eczane → anahtar → sayı (devir) */
    public function __construct(array $baslangic = [])
    {
        foreach ($baslangic as $eczaneId => $sayilar) {
            foreach ($sayilar as $anahtar => $sayi) {
                $this->sayilar[(int) $eczaneId][(string) $anahtar] = (int) $sayi;
            }
        }
    }

    public function sayi(int $eczaneId, string $anahtar): int
    {
        return $this->sayilar[$eczaneId][$anahtar] ?? 0;
    }

    /** Bir nöbeti sayar: o günün adı, ayı, mevsimi ve hafta tipi birer artar. */
    public function isle(int $eczaneId, DateTimeInterface $tarih): void
    {
        foreach (Takvim::anahtarlar($tarih) as $anahtar) {
            $this->sayilar[$eczaneId][$anahtar] = $this->sayi($eczaneId, $anahtar) + 1;
        }
    }

    /** @return array<string, int> */
    public function eczane(int $eczaneId): array
    {
        return $this->sayilar[$eczaneId] ?? [];
    }

    /** @return array<int, array<string, int>> */
    public function hepsi(): array
    {
        return $this->sayilar;
    }
}
