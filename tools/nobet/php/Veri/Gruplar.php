<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Gruplar: aynı gün aynı bölgeden aynı gruptan ikinci nöbetçi seçilmez; I kuralı iki grubun aynı gün nöbetçi
 * olmasını engeller; J kuralında günün ilk nöbetçisinin grubu, sonraki bölgelerin hangi gruplardan (eş grupları ya
 * da yedek grubu) seçileceğini belirler.
 *
 * Alt grup (`ust_id`, tek düzey): nöbet seçiminde üst grubuyla aynı sayılır. Döngü kurulurken eczanenin grubu kök
 * gruba çevrilir (`kokler()`); böylece aynı gün aynı bölgede üst grubu aynı iki eczane olmaz, I ve J kuralları üst
 * grup için tanımlanınca alt gruplara da geçer. Nöbet kaydında eczanenin kendi grubu yazılır.
 */
final class Gruplar
{
    public const TABLO = 'nobet_gruplar';

    private static function sema(): Schema
    {
        return Schema::object([
            'ad' => Field::string('Grubun adını yazın.')->max(80),
            'eslesme' => Field::integers()->optional()->max(50)->default([]),
            'yedek_id' => Field::integer()->optional()->min(1)->default(null),
            // Grubun bölgesi (eski arayüz; motor kullanmaz).
            'bolge_id' => Field::integer()->optional()->min(1)->default(null),
            'ust_id' => Field::integer()->optional()->min(1)->default(null),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function liste(): array
    {
        $sayilar = DB::table(Eczaneler::TABLO)->selectRaw('grup_id, count(*) as n')->whereNotNull('grup_id')->groupBy('grup_id')->pluck('n', 'grup_id');

        return DB::table(self::TABLO)->orderBy('ad')->get()->map(fn ($g) => [
            'id' => (int) $g->id,
            'ad' => $g->ad,
            'eslesme' => Kayit::json($g->eslesme),
            'yedek_id' => $g->yedek_id !== null ? (int) $g->yedek_id : null,
            'bolge_id' => isset($g->bolge_id) ? (int) $g->bolge_id : null,
            'ust_id' => isset($g->ust_id) ? (int) $g->ust_id : null,
            'eczane_sayisi' => (int) ($sayilar[$g->id] ?? 0),
        ])->all();
    }

    /** @return array<int, array{eslesme: list<int>, yedek: int|null}> motorun beklediği biçimde */
    public function motorIcin(): array
    {
        $gruplar = [];
        foreach ($this->liste() as $g) {
            $gruplar[$g['id']] = ['eslesme' => $g['eslesme'], 'yedek' => $g['yedek_id']];
        }

        return $gruplar;
    }

    /** @return array<int, int> grup → kök grubu (alt grup için üst grubu, diğerleri kendisi) */
    public function kokler(): array
    {
        $ust = DB::table(self::TABLO)->pluck('ust_id', 'id')->all();
        $kok = [];
        foreach ($ust as $id => $ustId) {
            $kok[(int) $id] = $ustId !== null && array_key_exists((int) $ustId, $ust) ? (int) $ustId : (int) $id;
        }

        return $kok;
    }

    /** @return array<string, mixed> */
    public function kaydet(?int $id, array $girdi): array
    {
        [$deger, $hatalar] = self::sema()->check(Kayit::bosKimlikler($girdi, ['yedek_id', 'bolge_id', 'ust_id']));
        $deger['yedek_id'] = Kayit::bosIse($deger['yedek_id']);
        $deger['bolge_id'] = Kayit::bosIse($deger['bolge_id']);
        $deger['ust_id'] = Kayit::bosIse($deger['ust_id']);
        if ($deger['bolge_id'] !== null && ! DB::table(Bolgeler::TABLO)->where('id', $deger['bolge_id'])->exists()) {
            $hatalar['bolge_id'] = 'Bölge bulunamadı.';
        }
        if ($id !== null) {
            Kayit::bul(self::TABLO, $id, 'Grup');
        }
        $var = DB::table(self::TABLO)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! isset($hatalar['ad']) && DB::table(self::TABLO)->where('ad', $deger['ad'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $hatalar['ad'] = 'Bu adla bir grup var.';
        }
        if (! isset($hatalar['eslesme']) && ($id !== null && in_array($id, $deger['eslesme'], true) || array_diff($deger['eslesme'], $var))) {
            $hatalar['eslesme'] = 'Eş gruplar var olan başka gruplar olmalı.';
        }
        if (! isset($hatalar['yedek_id']) && $deger['yedek_id'] !== null && ($deger['yedek_id'] === $id || ! in_array($deger['yedek_id'], $var, true))) {
            $hatalar['yedek_id'] = 'Yedek grup var olan başka bir grup olmalı.';
        }
        if (! isset($hatalar['ust_id']) && $deger['ust_id'] !== null) {
            $ust = DB::table(self::TABLO)->where('id', $deger['ust_id'])->first();
            if ($ust === null || $deger['ust_id'] === $id) {
                $hatalar['ust_id'] = 'Üst grup var olan başka bir grup olmalı.';
            } elseif ($ust->ust_id !== null) {
                $hatalar['ust_id'] = 'Üst grup kendisi bir alt grup olamaz (tek düzey).';
            } elseif ($id !== null && DB::table(self::TABLO)->where('ust_id', $id)->exists()) {
                $hatalar['ust_id'] = 'Bu grubun alt grupları var; başka bir grubun altına alınamaz.';
            }
        }
        Schema::fail($hatalar);

        $satir = ['ad' => $deger['ad'], 'eslesme' => json_encode(array_values($deger['eslesme'])), 'yedek_id' => $deger['yedek_id'], 'bolge_id' => $deger['bolge_id'], 'ust_id' => $deger['ust_id'], 'updated_at' => Clock::now()];
        if ($id === null) {
            $id = (int) DB::table(self::TABLO)->insertGetId($satir + ['created_at' => Clock::now()]);
        } else {
            DB::table(self::TABLO)->where('id', $id)->update($satir);
        }

        return ['id' => $id, 'ad' => $deger['ad'], 'eslesme' => array_values($deger['eslesme']), 'yedek_id' => $deger['yedek_id'], 'bolge_id' => $deger['bolge_id'], 'ust_id' => $deger['ust_id']];
    }

    /** Siler; başka grupların eş ve yedek listesinden de çıkarır, alt grupları ana grup olur. */
    public function sil(int $id): string
    {
        $grup = Kayit::bul(self::TABLO, $id, 'Grup');
        if (DB::table(Eczaneler::TABLO)->where('grup_id', $id)->exists()) {
            throw new DomainException('nobet_grup_dolu', 'Bu grupta eczane var: önce eczanelerin grubunu değiştirin.');
        }
        DB::transaction(function () use ($id) {
            foreach (DB::table(self::TABLO)->where('id', '!=', $id)->get() as $g) {
                $eslesme = Kayit::json($g->eslesme);
                $yeni = array_values(array_diff($eslesme, [$id]));
                if ($yeni !== $eslesme || (int) $g->yedek_id === $id) {
                    DB::table(self::TABLO)->where('id', $g->id)->update([
                        'eslesme' => json_encode($yeni),
                        'yedek_id' => (int) $g->yedek_id === $id ? null : $g->yedek_id,
                    ]);
                }
            }
            DB::table(self::TABLO)->where('ust_id', $id)->update(['ust_id' => null]);
            DB::table(self::TABLO)->where('id', $id)->delete();
        });

        return (string) $grup->ad;
    }
}
