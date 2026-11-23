<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Mazeretler: bir güne bağlı not (nöbet değişikliğinin nedeni) ya da eczacının talebi (Eczacim::talep).
 * Durum: kayit (yöneticinin notu) | talep (eczacı bildirdi, karar bekliyor) | onay | ret. Bildiren `olusturan_id` (panel
 * hesabı) ya da `uye_id` (site üyesi).
 */
final class Mazeretler
{
    public const TABLO = 'nobet_mazeretler';

    public const TURLER = ['mazeret', 'degisiklik', 'not'];

    public const KAYIT = 'kayit';

    public const TALEP = 'talep';

    public const KARARLAR = ['onay', 'ret'];

    /**
     * Talebe yöneticinin kararı (onay | ret, isteğe bağlı not). Yalnızca bekleyen talepte. Karar veren panel hesabı
     * (yönetim arayüzü) ya da odanın yöneticisi olan üye (Hesabım › Oda yönetimi).
     *
     * @return array{id: int, ad: string, karar: string, eczane: ?string, tarih: string}
     */
    public function karar(int $id, array $girdi, ?int $kullanici, ?int $uye = null): array
    {
        $m = Kayit::bul(self::TABLO, $id, 'Mazeret');
        if ($m->durum !== self::TALEP) {
            throw new ConflictException('nobet_mazeret_karar', 'Bu kayıt karar bekleyen bir talep değil.');
        }
        $karar = (string) ($girdi['karar'] ?? '');
        if (! in_array($karar, self::KARARLAR, true)) {
            throw ValidationException::field('karar', 'Karar onay ya da ret olmalı.');
        }
        $not = mb_substr(trim((string) ($girdi['not'] ?? '')), 0, 500);
        DB::table(self::TABLO)->where('id', $id)->update([
            'durum' => $karar, 'karar_notu' => $not !== '' ? $not : null, 'karar_veren_id' => $kullanici, 'karar_veren_uye_id' => $uye,
            'karar_tarihi' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        $eczane = $m->eczane_id ? DB::table(Eczaneler::TABLO)->where('id', $m->eczane_id)->value('ad') : null;
        $tarih = substr((string) $m->tarih, 0, 10);
        Bildirim::kararVerildi($id, $karar, $tarih, $not !== '' ? $not : null);

        return ['id' => $id, 'ad' => trim(($eczane ?? '').' · '.$tarih, ' ·'), 'karar' => $karar, 'eczane' => $eczane, 'tarih' => $tarih];
    }

    /** @return list<object> */
    public function liste(): array
    {
        return DB::table(self::TABLO)->orderByDesc('tarih')->orderByDesc('id')->get()->all();
    }

    /**
     * @param  array{tarih?: mixed, aciklama?: mixed, tur?: mixed, eczane_id?: mixed}  $girdi
     * @return array{id: int, ad: string}
     */
    public function kaydet(?int $id, array $girdi, ?int $kullanici, string $durum = self::KAYIT, ?int $uye = null): array
    {
        $mevcut = $id !== null ? Kayit::bul(self::TABLO, $id, 'Mazeret') : null;
        $girdi += $mevcut ? ['tarih' => substr((string) $mevcut->tarih, 0, 10), 'aciklama' => $mevcut->aciklama, 'tur' => $mevcut->tur, 'eczane_id' => $mevcut->eczane_id] : [];
        [$deger, $hatalar] = Schema::object([
            'aciklama' => Field::string('Mazeretin açıklamasını yazın.')->max(500)->multiline(),
        ])->check($girdi);
        $tarih = substr(trim((string) ($girdi['tarih'] ?? '')), 0, 10);
        if (! Kayit::tarihMi($tarih)) {
            $hatalar['tarih'] = 'Geçerli bir tarih seçin.';
        }
        $tur = (string) ($girdi['tur'] ?? 'mazeret') ?: 'mazeret';
        if (! in_array($tur, self::TURLER, true)) {
            $hatalar['tur'] = 'Tür mazeret, değişiklik ya da not olmalı.';
        }
        $eczane = Kayit::bosIse(filter_var($girdi['eczane_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
        if ($eczane !== null && ! DB::table(Eczaneler::TABLO)->where('id', $eczane)->exists()) {
            $hatalar['eczane_id'] = 'Eczane bulunamadı.';
        }
        if ($hatalar !== []) {
            throw ValidationException::fields($hatalar);
        }

        $satir = ['tarih' => $tarih, 'aciklama' => $deger['aciklama'], 'tur' => $tur, 'eczane_id' => $eczane, 'updated_at' => Clock::now()];
        if ($id === null) {
            $id = (int) DB::table(self::TABLO)->insertGetId($satir + ['durum' => $durum, 'olusturan_id' => $kullanici, 'uye_id' => $uye, 'created_at' => Clock::now()]);
        } else {
            DB::table(self::TABLO)->where('id', $id)->update($satir);
        }

        return ['id' => $id, 'ad' => $tarih.' '.mb_substr($deger['aciklama'], 0, 60)];
    }

    public function sil(int $id): string
    {
        $m = Kayit::bul(self::TABLO, $id, 'Mazeret');
        DB::table(self::TABLO)->where('id', $id)->delete();

        return substr((string) $m->tarih, 0, 10).' '.mb_substr((string) $m->aciklama, 0, 60);
    }
}
