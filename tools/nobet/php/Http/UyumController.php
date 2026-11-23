<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Veri\Uyum;

/**
 * Eczane yönetim arayüzünün uyum uçları (Veri\Uyum; okuma `km_view_nobet`, yazma `km_manage_nobet`):
 *
 *   GET    /admin/nobet/eski/{tablo}?sutun=&deger=&baslangic=&bitis=   eski biçimde satırlar
 *   GET    /admin/nobet/eski/{tablo}/{id}
 *   POST   /admin/nobet/eski/{tablo}            eski sütun adlarıyla ekle
 *   PUT    /admin/nobet/eski/{tablo}/{id}       kısmi güncelle
 *   DELETE /admin/nobet/eski/{tablo}/{id}
 *   POST   /admin/nobet/eski/{tablo}/toplu      Excel'den toplu ekleme { rows: [...] } (raporlar, eczaneler; hepsi ya da hiçbiri)
 *   GET    /nobet/oda                            odanın adı, il / ilçe, harita merkezi (herkese açık; giriş sayfası da okur)
 *
 * Tablo adı rotada izin listesiyle sınırlıdır (Uyum::OKUNUR / YAZILIR); başka tablo 404.
 */
final class UyumController extends Controller
{
    public function oda(): array
    {
        return Uyum::oda();
    }

    public function liste(Request $request, string $tablo): array
    {
        return (new Uyum)->liste($tablo, $request->only(['sutun', 'deger', 'baslangic', 'bitis']));
    }

    public function bul(string $tablo, int $id): array
    {
        return (new Uyum)->bul($tablo, $id);
    }

    public function ekle(Request $request, string $tablo): array
    {
        return $this->kaydet($request, $tablo, null);
    }

    public function guncelle(Request $request, string $tablo, int $id): array
    {
        return $this->kaydet($request, $tablo, $id);
    }

    public function sil(string $tablo, int $id): array
    {
        $ad = (new Uyum)->sil($tablo, $id);
        Activity::subject('nobet_'.$tablo, $id, $ad);
        Activity::with(['tur' => Uyum::ADLAR[$tablo] ?? $tablo, 'ad' => $ad]);

        return ['id' => $id, 'deleted' => true];
    }

    public function toplu(Request $request, string $tablo): array
    {
        $satirlar = $request->input('rows', $request->input('body.rows', []));
        $sonuc = (new Uyum)->toplu($tablo, is_array($satirlar) ? $satirlar : []);
        Activity::with(['tur' => Uyum::ADLAR[$tablo] ?? $tablo, 'ad' => $sonuc['ad'], 'eklenen' => $sonuc['eklenen']]);

        return $sonuc;
    }

    private function kaydet(Request $request, string $tablo, ?int $id): array
    {
        $govde = $request->input('body', $request->except(['body']));
        $kayit = (new Uyum)->kaydet($tablo, $id, is_array($govde) ? $govde : []);
        Activity::subject('nobet_'.$tablo, $kayit['id'], $kayit['ad']);
        Activity::with(['tur' => Uyum::ADLAR[$tablo] ?? $tablo, 'ad' => $kayit['ad']]);

        return $kayit;
    }
}
