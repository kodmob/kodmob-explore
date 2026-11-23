<?php

namespace KodmobTools\Nobet\Motor;

/** Motorun gördüğü kadarıyla eczane: kimlik, bölge, grup ve (coğrafi kural için) konum. */
final class Eczane
{
    public function __construct(
        public readonly int $id,
        public readonly int $bolgeId,
        public readonly ?int $grupId = null,
        public readonly ?float $enlem = null,
        public readonly ?float $boylam = null,
    ) {}

    public function konumuVar(): bool
    {
        return $this->enlem !== null && $this->boylam !== null;
    }

    /** İki eczane arası kuş uçuşu (km, haversine); konumu olmayan için null. */
    public function uzaklik(self $diger): ?float
    {
        if (! $this->konumuVar() || ! $diger->konumuVar()) {
            return null;
        }
        $dLat = deg2rad($diger->enlem - $this->enlem);
        $dLon = deg2rad($diger->boylam - $this->boylam);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->enlem)) * cos(deg2rad($diger->enlem)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
