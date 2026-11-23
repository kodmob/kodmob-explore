<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Veri\Eczacim;
use KodmobTools\Nobet\Veri\Oda;
use KodmobTools\Nobet\Veri\Takaslar;

/**
 * Hesabım › Nöbetlerim (site üyesi eczacı; oturum `km_member` çerezinde, Veri\Oda::uye). Eczane üyenin hesabına
 * bağlı olandır (`nobet_eczaneler.uye_id`); bağlı değilse yazma uçları 403.
 *
 *   GET    /members/nobet                                eczane, gelecek nöbetler, mazeret ve takas talepleri
 *   POST   /members/nobet/mazeret       { tarih, aciklama }
 *   DELETE /members/nobet/mazeret/{id}                    bekleyen talebini geri al
 *   GET    /members/nobet/takas-adaylari?nobet={id}       bu nöbetle takas edilebilecek nöbetler
 *   POST   /members/nobet/takas         { nobet_id, karsi_nobet_id, aciklama? }
 *   POST   /members/nobet/takas/{id}/yanit  { yanit: kabul|ret }   karşı eczane
 *   DELETE /members/nobet/takas/{id}                      teklif eden geri alır
 */
final class HesapController extends Controller
{
    public function ozet(Request $request): JsonResponse
    {
        $uye = Oda::uye($request);

        return $this->noStore((new Eczacim)->eczaneOzeti(Oda::uyeninEczanesi((int) $uye['id'])) + [
            'takas_onayi' => Takaslar::onayGerekir(),
            'oda' => Oda::yoneticiMi((int) $uye['id']),
        ]);
    }

    public function mazeret(Request $request): JsonResponse
    {
        $uye = Oda::uye($request);

        return $this->noStore((new Eczacim)->mazeretBildir($this->eczane($uye), $this->input($request), null, (int) $uye['id']));
    }

    public function mazeretGeriAl(Request $request, int $id): JsonResponse
    {
        (new Eczacim)->mazeretGeriAl($this->eczane(Oda::uye($request)), $id);

        return $this->noStore(['id' => $id, 'deleted' => true]);
    }

    public function adaylar(Request $request): JsonResponse
    {
        $eczane = $this->eczane(Oda::uye($request));

        return $this->noStore(['adaylar' => (new Takaslar)->adaylar($eczane, (int) $request->query('nobet', '0'))]);
    }

    public function takas(Request $request): JsonResponse
    {
        $uye = Oda::uye($request);

        return $this->noStore((new Takaslar)->teklif($this->eczane($uye), $this->input($request), null, (int) $uye['id']));
    }

    public function yanit(Request $request, int $id): JsonResponse
    {
        return $this->noStore((new Takaslar)->yanit($this->eczane(Oda::uye($request)), $id, (string) ($this->input($request)['yanit'] ?? '')));
    }

    public function takasIptal(Request $request, int $id): JsonResponse
    {
        (new Takaslar)->iptal($this->eczane(Oda::uye($request)), $id);

        return $this->noStore(['id' => $id, 'durum' => Takaslar::IPTAL]);
    }

    /** @param array<string, mixed> $uye */
    private function eczane(array $uye): object
    {
        return Eczacim::bagli(Oda::uyeninEczanesi((int) $uye['id']));
    }
}
