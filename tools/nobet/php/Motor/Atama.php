<?php

namespace KodmobTools\Nobet\Motor;

use DateTimeImmutable;

/**
 * Bir nöbet: hangi gün, hangi vardiya, hangi bölgede hangi eczane. `gerekce` seçimin nasıl yapıldığını anlatır
 * ("neden ben?" sorusunun cevabı): sırayla hangi kuralın adayları kaçtan kaça indirdiği ve son kuradaki aday sayısı.
 */
final class Atama
{
    public const TUM_GUN = 'tumgun';

    public const GUNDUZ = 'gunduz';

    public const GECE = 'gece';

    /**
     * @param  array{adimlar: list<array{kural: string, once: int, sonra: int}>, kura: int}  $gerekce
     */
    public function __construct(
        public readonly DateTimeImmutable $tarih,
        public readonly string $vardiya,
        public readonly int $bolgeId,
        public readonly int $eczaneId,
        public readonly ?int $grupId,
        public readonly bool $ozelGun,
        public readonly array $gerekce,
    ) {}

    /** @return array{tarih: string, vardiya: string, bolge: int, eczane: int, grup: int|null, ozel_gun: bool, gerekce: array<string, mixed>} */
    public function dizi(): array
    {
        return [
            'tarih' => $this->tarih->format('Y-m-d'),
            'vardiya' => $this->vardiya,
            'bolge' => $this->bolgeId,
            'eczane' => $this->eczaneId,
            'grup' => $this->grupId,
            'ozel_gun' => $this->ozelGun,
            'gerekce' => $this->gerekce,
        ];
    }
}
