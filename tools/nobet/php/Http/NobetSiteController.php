<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;
use Illuminate\Http\Request;
use KodmobTools\Nobet\NobetModule;
use KodmobTools\Nobet\Veri\Nobetler;

/**
 * Sitenin Nöbetçi Eczaneler sayfası (herkese açık; tanımın `public`'inde gerekçesiyle).
 *
 *   GET /nobet/nobetciler?gun=7   bugünden (devir saatine göre) başlayarak en çok 31 günün nöbetçileri
 */
final class NobetSiteController extends Controller
{
    public function nobetciler(Request $request): array
    {
        $gun = filter_var($request->query('gun', 7), FILTER_VALIDATE_INT) ?: 7;

        return (new Nobetler)->nobetciler($gun, (string) SettingsService::instance()->get(NobetModule::DEVIR_SAATI));
    }
}
