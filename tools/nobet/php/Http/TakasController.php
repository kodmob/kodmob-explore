<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Veri\Takaslar;

/**
 * Nöbet takasları yönetim arayüzünde (Mazeretler sayfası; panel hesabı):
 *
 *   GET  /admin/nobet/takaslar                   km_view_nobet    onay bekleyenler önce
 *   POST /admin/nobet/takaslar/{id}/karar        km_manage_nobet  { karar: onay|ret, not? }
 */
final class TakasController extends Controller
{
    public function liste(): array
    {
        return ['takaslar' => (new Takaslar)->liste(), 'takas_onayi' => Takaslar::onayGerekir()];
    }

    public function karar(Request $request, int $id): array
    {
        $sonuc = (new Takaslar)->karar($id, $this->input($request), (int) $request->user()->id, null);
        Activity::subject('nobet_takas', $id, $sonuc['ad']);
        Activity::with(['ad' => $sonuc['ad'], 'karar' => $sonuc['karar'], 'fields' => []]);

        return $sonuc;
    }
}
