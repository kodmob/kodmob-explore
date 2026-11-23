<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Özel günler (bayram, yılbaşı): o günün nöbeti sayaçlara girmez; O kuralıyla döngüde boş bırakılır. */
final class OzelGunler
{
    public const TABLO = 'nobet_ozel_gunler';

    /** @return list<array<string, mixed>> */
    public function liste(): array
    {
        return DB::table(self::TABLO)->orderBy('tarih')->get()
            ->map(fn ($g) => ['id' => (int) $g->id, 'tarih' => substr((string) $g->tarih, 0, 10), 'ad' => $g->ad])->all();
    }

    /** @return list<string> Y-m-d, aralıkta (iki uç dahil) */
    public function aralikta(string $baslangic, string $bitis): array
    {
        return DB::table(self::TABLO)->whereBetween('tarih', [$baslangic, $bitis])->orderBy('tarih')->pluck('tarih')
            ->map(fn ($t) => substr((string) $t, 0, 10))->all();
    }

    /** @return array<string, mixed> */
    public function kaydet(?int $id, array $girdi): array
    {
        [$deger, $hatalar] = Schema::object(['ad' => Field::string('Özel günün adını yazın.')->max(120)])->check($girdi);
        $tarih = substr(trim((string) ($girdi['tarih'] ?? '')), 0, 10);
        if (! Kayit::tarihMi($tarih)) {
            $hatalar['tarih'] = 'Geçerli bir tarih seçin.';
        } elseif (DB::table(self::TABLO)->whereDate('tarih', $tarih)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $hatalar['tarih'] = 'Bu tarih zaten özel gün.';
        }
        $eskiTarih = $id !== null ? substr((string) Kayit::bul(self::TABLO, $id, 'Özel gün')->tarih, 0, 10) : null;
        Schema::fail($hatalar);

        $satir = ['tarih' => $tarih, 'ad' => $deger['ad'], 'updated_at' => Clock::now()];
        DB::transaction(function () use (&$id, $satir, $tarih, $eskiTarih) {
            if ($id === null) {
                $id = (int) DB::table(self::TABLO)->insertGetId($satir + ['created_at' => Clock::now()]);
            } else {
                DB::table(self::TABLO)->where('id', $id)->update($satir);
            }
            // O günün nöbetleri özel gün sayılır (sayaçlara girmez); tarih değiştiyse eski günün işareti kalkar.
            if ($eskiTarih !== null && $eskiTarih !== $tarih) {
                Nobetler::ozelGunIsaretle($eskiTarih, false);
            }
            Nobetler::ozelGunIsaretle($tarih, true);
        });

        return ['id' => $id, 'tarih' => $tarih, 'ad' => $deger['ad']];
    }

    /** Siler; o günün nöbetleri yeniden sayaçlara girer (nöbetlerin kendisi kalır). */
    public function sil(int $id): string
    {
        $gun = Kayit::bul(self::TABLO, $id, 'Özel gün');
        $tarih = substr((string) $gun->tarih, 0, 10);
        DB::transaction(function () use ($id, $tarih) {
            DB::table(self::TABLO)->where('id', $id)->delete();
            Nobetler::ozelGunIsaretle($tarih, false);
        });

        return $tarih.' '.$gun->ad;
    }
}
