<?php

namespace KodmobTools\Nobet\Motor;

/** Bir günün (vardiyanın) o ana kadarki nöbetçileri; bütün bölgeler ve L'nin ikinci turu aynı durumu paylaşır. */
final class GunDurumu
{
    /** @var list<Eczane> */
    private array $secilenler = [];

    public function ekle(Eczane $eczane): void
    {
        $this->secilenler[] = $eczane;
    }

    public function bos(): bool
    {
        return $this->secilenler === [];
    }

    /** @return list<Eczane> */
    public function secilenler(): array
    {
        return $this->secilenler;
    }

    /** @return list<int> */
    public function idler(): array
    {
        return array_map(fn (Eczane $e) => $e->id, $this->secilenler);
    }

    /** @return list<int> */
    public function gruplar(): array
    {
        return array_values(array_filter(array_map(fn (Eczane $e) => $e->grupId, $this->secilenler), fn ($g) => $g !== null));
    }

    /** Günün ilk nöbetçisinin grubu (J). */
    public function ilkGrup(): ?int
    {
        return $this->secilenler[0]->grupId ?? null;
    }

    /**
     * Bölgenin bugün seçilebilecek eczaneleri: bugün seçilmemiş olanlar; `grupFarkli` ise ayrıca bu bölgede bugün
     * seçilmiş bir grupta olmayanlar (grubu olmayan eczane bu kısıta takılmaz).
     *
     * @param  list<Eczane>  $eczaneler
     * @return list<Eczane>
     */
    public function adaylar(int $bolgeId, array $eczaneler, bool $grupFarkli): array
    {
        $secilen = array_flip($this->idler());
        $bolgeGruplari = [];
        foreach ($this->secilenler as $s) {
            if ($s->bolgeId === $bolgeId && $s->grupId !== null) {
                $bolgeGruplari[$s->grupId] = true;
            }
        }

        return array_values(array_filter($eczaneler, fn (Eczane $e) => ! isset($secilen[$e->id])
            && (! $grupFarkli || $e->grupId === null || ! isset($bolgeGruplari[$e->grupId]))));
    }
}
