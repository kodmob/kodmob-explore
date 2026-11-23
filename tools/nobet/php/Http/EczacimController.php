<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Veri\Eczacim;
use KodmobTools\Nobet\Veri\Mazeretler;

/**
 * Eczacının alanı ve mazeret talepleri (Veri\Eczacim, Veri\Mazeretler::karar):
 *
 *   GET    /admin/nobet/benim                    km_view_nobet   bağlı eczane, gelecek nöbetler, talepler
 *   POST   /admin/nobet/benim/mazeret            km_view_nobet   { tarih, aciklama } → talep
 *   DELETE /admin/nobet/benim/mazeret/{id}       km_view_nobet   bekleyen kendi talebini geri al
 *   POST   /admin/nobet/mazeretler/{id}/karar    km_manage_nobet { karar: onay|ret, not? }
 */
final class EczacimController extends Controller
{
    public function ozet(Request $request): array
    {
        return (new Eczacim)->ozet($request->user());
    }

    public function talep(Request $request): array
    {
        $sonuc = (new Eczacim)->talep($request->user(), $this->input($request));
        Activity::subject('nobet_mazeret', $sonuc['id'], $sonuc['ad']);
        Activity::with(['ad' => $sonuc['ad'], 'fields' => []]);

        return $sonuc;
    }

    public function geriAl(Request $request, int $id): array
    {
        $ad = (new Eczacim)->geriAl($request->user(), $id);
        Activity::subject('nobet_mazeret', $id, $ad);
        Activity::with(['ad' => $ad]);

        return ['id' => $id, 'deleted' => true];
    }

    public function karar(Request $request, int $id): array
    {
        $sonuc = (new Mazeretler)->karar($id, $this->input($request), (int) $request->user()->id);
        Activity::subject('nobet_mazeret', $id, $sonuc['ad']);
        Activity::with(['ad' => $sonuc['ad'], 'karar' => $sonuc['karar'], 'fields' => []]);

        return $sonuc;
    }
}
