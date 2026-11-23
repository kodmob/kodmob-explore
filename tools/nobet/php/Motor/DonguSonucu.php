<?php

namespace KodmobTools\Nobet\Motor;

/** Motorun çıktısı: atamalar (atama sırasıyla: cumartesiler, pazarlar, hafta içi), uyarılar ve kuranın tohumu. */
final class DonguSonucu
{
    /**
     * @param  list<Atama>  $atamalar
     * @param  list<string>  $uyarilar  döngüyü durdurmayan ama yöneticinin bilmesi gereken durumlar (esnetilen kural…)
     */
    public function __construct(
        public readonly array $atamalar,
        public readonly array $uyarilar,
        public readonly int $tohum,
    ) {}

    /** @return list<Atama> tarihe, vardiyaya (gündüz, gece, tüm gün) ve bölgeye göre */
    public function sirali(): array
    {
        $sira = [Atama::GUNDUZ => 0, Atama::GECE => 1, Atama::TUM_GUN => 2];
        $liste = $this->atamalar;
        usort($liste, fn (Atama $a, Atama $b) => [$a->tarih, $sira[$a->vardiya], $a->bolgeId]
            <=> [$b->tarih, $sira[$b->vardiya], $b->bolgeId]);

        return $liste;
    }
}
