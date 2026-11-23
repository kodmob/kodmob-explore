<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Motor\Kural;
use KodmobTools\Nobet\Veri\Bolgeler;
use KodmobTools\Nobet\Veri\Donguler;
use KodmobTools\Nobet\Veri\Eczaneler;
use KodmobTools\Nobet\Veri\Gruplar;
use KodmobTools\Nobet\Veri\Nobetler;
use KodmobTools\Nobet\Veri\OzelGunler;

/**
 * Panel › Nöbet (`module:nobet`). Okuma `km_view_nobet`, yazma `km_manage_nobet`.
 *
 *   GET    /admin/nobet/kayitlar                    bölgeler, gruplar, eczaneler, özel günler, kurallar (sayfanın verisi)
 *   GET    /admin/nobet/takvim?baslangic=&bitis=    aralıktaki nöbetler (en çok 400 gün)
 *   POST|PUT|DELETE /admin/nobet/{bolgeler|gruplar|eczaneler|ozel-gunler}[/{id}]
 *   GET    /admin/nobet/donguler                    döngüler
 *   POST   /admin/nobet/donguler/onizle             döngüyü kaydetmeden kurar (işlem geçmişine yazılmaz)
 *   POST   /admin/nobet/donguler                    döngüyü kurar ve kaydeder;  DELETE …/{id} döngüyü ve nöbetlerini siler
 *   PUT    /admin/nobet/nobetler/{id}               { eczane_id } nöbeti başka eczaneye verir (aynı bölge)
 */
final class NobetAdminController extends Controller
{
    private const KAYITLAR = [
        'bolgeler' => [Bolgeler::class, 'bolge', 'nobet_bolge'],
        'gruplar' => [Gruplar::class, 'grup', 'nobet_grup'],
        'eczaneler' => [Eczaneler::class, 'eczane', 'nobet_eczane'],
        'ozel-gunler' => [OzelGunler::class, 'ozel_gun', 'nobet_ozel_gun'],
    ];

    public function kayitlar(): array
    {
        return [
            'bolgeler' => (new Bolgeler)->liste(),
            'gruplar' => (new Gruplar)->liste(),
            'eczaneler' => (new Eczaneler)->liste(),
            'ozel_gunler' => (new OzelGunler)->liste(),
            'kurallar' => array_map(fn (Kural $k) => ['harf' => $k->value, 'etiket' => $k->etiket(), 'yapisal' => $k->yapisal()], Kural::cases()),
            'devir_anahtarlari' => Eczaneler::devirAnahtarlari(),
        ];
    }

    public function takvim(Request $request): array
    {
        return (new Nobetler)->takvim((string) $request->query('baslangic', ''), (string) $request->query('bitis', ''));
    }

    /** Tür rotanın varsayılanından, kimlik rotadan (Laravel parametreleri sırayla geçirir; adla okunur). */
    public function kaydet(Request $request): array
    {
        $tur = (string) $request->route('tur');
        $id = $request->route('id') !== null ? (int) $request->route('id') : null;
        [$sinif, , $konu] = self::KAYITLAR[$tur];
        $kayit = (new $sinif)->kaydet($id, $request->all());
        $ad = (string) ($kayit['ad'] ?? '');
        Activity::subject($konu, (int) $kayit['id'], $ad);
        Activity::with(['ad' => $tur === 'ozel-gunler' ? $kayit['tarih'].' '.$ad : $ad]);

        return $kayit;
    }

    public function sil(Request $request): array
    {
        $tur = (string) $request->route('tur');
        $id = (int) $request->route('id');
        [$sinif, , $konu] = self::KAYITLAR[$tur];
        $ad = (new $sinif)->sil($id);
        Activity::subject($konu, $id, $ad);
        Activity::with(['ad' => $ad]);

        return ['deleted' => true];
    }

    public function donguler(): array
    {
        return ['items' => (new Donguler)->liste()];
    }

    public function onizle(Request $request): array
    {
        return (new Donguler)->olustur($request->all(), $request->user()?->id, false);
    }

    public function olustur(Request $request): array
    {
        $sonuc = (new Donguler)->olustur($request->all(), $request->user()?->id, true);
        Activity::subject('nobet_dongu', (int) $sonuc['dongu']['id'], (string) $sonuc['dongu']['ad']);
        Activity::with(['ad' => $sonuc['dongu']['ad'], 'atama_sayisi' => $sonuc['atama_sayisi']]);

        return $sonuc;
    }

    public function donguSil(int $id): array
    {
        $sonuc = (new Donguler)->sil($id);
        Activity::subject('nobet_dongu', $id, $sonuc['ad']);
        Activity::with($sonuc);

        return ['deleted' => true] + $sonuc;
    }

    public function degistir(Request $request, int $id): array
    {
        $sonuc = (new Nobetler)->degistir($id, $request->input('eczane_id'));
        $tarih = substr((string) $sonuc['nobet']['tarih'], 0, 10);
        Activity::subject('nobet_nobet', $id, $tarih);
        Activity::with(['tarih' => $tarih, 'onceki' => $sonuc['onceki'], 'yeni' => $sonuc['yeni']]);

        return $sonuc;
    }
}
