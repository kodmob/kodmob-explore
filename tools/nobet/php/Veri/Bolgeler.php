<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Bölgeler: her gün her bölgeye ayrı nöbetçi yazılır (Merkez, Doğu, Batı…). */
final class Bolgeler
{
    public const TABLO = 'nobet_bolgeler';

    private static function sema(): Schema
    {
        return Schema::object([
            'ad' => Field::string('Bölgenin adını yazın.')->max(80),
            'sira' => Field::integer()->optional()->min(0)->max(999)->default(0),
        ]);
    }

    /** @return list<array<string, mixed>> sırasıyla, eczane sayılarıyla */
    public function liste(): array
    {
        $sayilar = DB::table(Eczaneler::TABLO)->selectRaw('bolge_id, count(*) as n')->groupBy('bolge_id')->pluck('n', 'bolge_id');

        return DB::table(self::TABLO)->orderBy('sira')->orderBy('id')->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'ad' => $b->ad, 'sira' => (int) $b->sira, 'eczane_sayisi' => (int) ($sayilar[$b->id] ?? 0)])
            ->all();
    }

    /** @return array<int, string> kimlik → ad, sırasıyla */
    public function adlar(): array
    {
        return DB::table(self::TABLO)->orderBy('sira')->orderBy('id')->pluck('ad', 'id')->map(fn ($a) => (string) $a)->all();
    }

    /** @return array<string, mixed> */
    public function kaydet(?int $id, array $girdi): array
    {
        $deger = self::sema()->validate($girdi);
        if ($id !== null) {
            Kayit::bul(self::TABLO, $id, 'Bölge');
        }
        if (DB::table(self::TABLO)->where('ad', $deger['ad'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            Schema::fail(['ad' => 'Bu adla bir bölge var.']);
        }
        $satir = ['ad' => $deger['ad'], 'sira' => $deger['sira'], 'updated_at' => Clock::now()];
        if ($id === null) {
            $id = (int) DB::table(self::TABLO)->insertGetId($satir + ['created_at' => Clock::now()]);
        } else {
            DB::table(self::TABLO)->where('id', $id)->update($satir);
        }

        return ['id' => $id, 'ad' => $deger['ad'], 'sira' => $deger['sira']];
    }

    public function sil(int $id): string
    {
        $bolge = Kayit::bul(self::TABLO, $id, 'Bölge');
        if (DB::table(Eczaneler::TABLO)->where('bolge_id', $id)->exists()) {
            throw new DomainException('nobet_bolge_dolu', 'Bu bölgede eczane var: önce eczaneleri başka bölgeye taşıyın.');
        }
        DB::table(self::TABLO)->where('id', $id)->delete();

        return (string) $bolge->ad;
    }
}
