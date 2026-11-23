<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kodmob\Members\Organizations;
use KodmobTools\Nobet\Veri\Bolgeler;
use KodmobTools\Nobet\Veri\Eczacim;
use KodmobTools\Nobet\Veri\Eczaneler;
use KodmobTools\Nobet\Veri\Mazeretler;
use KodmobTools\Nobet\Veri\Oda;
use KodmobTools\Nobet\Veri\Takaslar;

/**
 * Hesabım › Oda yönetimi: odanın kurumundaki yönetici ya da sahip (Veri\Oda::yonetici; seçili kurumdan bağımsız, her
 * uçta sunucuda). Planlama (döngü, eczane, bölge) yönetim arayüzünde kalır; burada eczacıların talepleri ve hesap bağı.
 *
 *   GET  /members/nobet/oda                                 talepler, takaslar, eczaneler ve bağlı hesaplar, odanın üyeleri
 *   POST /members/nobet/oda/mazeret/{id}/karar   { karar: onay|ret, not? }
 *   POST /members/nobet/oda/takas/{id}/karar     { karar: onay|ret, not? }
 *   PUT  /members/nobet/oda/eczaneler/{id}/uye   { uye_id: int|null }   eczaneyi odanın bir üyesine bağla (null: bağı kaldır)
 */
final class OdaController extends Controller
{
    public function ozet(Request $request): JsonResponse
    {
        Oda::yonetici($request);

        return $this->noStore($this->veri());
    }

    public function mazeretKarari(Request $request, int $id): JsonResponse
    {
        $uye = Oda::yonetici($request);
        (new Mazeretler)->karar($id, $this->input($request), null, (int) $uye['id']);

        return $this->noStore($this->veri());
    }

    public function takasKarari(Request $request, int $id): JsonResponse
    {
        $uye = Oda::yonetici($request);
        (new Takaslar)->karar($id, $this->input($request), null, (int) $uye['id']);

        return $this->noStore($this->veri());
    }

    public function bagla(Request $request, int $id): JsonResponse
    {
        Oda::yonetici($request);
        $eczane = DB::table(Eczaneler::TABLO)->where('id', $id)->first() ?? throw NotFoundException::message('Eczane bulunamadı.');
        $girdi = $this->input($request);
        $uyeId = isset($girdi['uye_id']) && $girdi['uye_id'] !== '' ? (int) filter_var($girdi['uye_id'], FILTER_VALIDATE_INT) : null;
        if ($uyeId !== null) {
            if ($uyeId <= 0 || Organizations::role(Oda::kurum(), $uyeId) === null) {
                throw ValidationException::field('uye_id', 'Odanın üyelerinden birini seçin (önce Kurum üyeleri\'nden davet edin).');
            }
            $baska = DB::table(Eczaneler::TABLO)->where('uye_id', $uyeId)->where('id', '!=', $id)->value('ad');
            if ($baska !== null) {
                throw new ConflictException('nobet_uye_bagli', "Bu hesap {$baska} eczanesine bağlı; önce oradaki bağı kaldırın.");
            }
        }
        DB::table(Eczaneler::TABLO)->where('id', $eczane->id)->update(['uye_id' => $uyeId]);

        return $this->noStore($this->veri());
    }

    /** @return array<string, mixed> */
    private function veri(): array
    {
        $eczaneAdi = DB::table(Eczaneler::TABLO)->pluck('ad', 'id');
        $mazeretler = DB::table(Mazeretler::TABLO)->where('durum', '!=', Mazeretler::KAYIT)
            ->orderByRaw("CASE durum WHEN 'talep' THEN 0 ELSE 1 END")->orderByDesc('tarih')->orderByDesc('id')->limit(100)->get()
            ->map(static fn ($m) => Eczacim::mazeretSatiri($m) + ['eczane_id' => $m->eczane_id ? (int) $m->eczane_id : null, 'eczane' => (string) ($eczaneAdi[$m->eczane_id] ?? '')])->all();
        $uyeler = Organizations::members(Oda::kurum());
        $uyeAdi = array_column($uyeler, null, 'member_id');
        $bolgeler = DB::table(Bolgeler::TABLO)->pluck('ad', 'id');
        $eczaneler = DB::table(Eczaneler::TABLO)->orderBy('ad')->get(['id', 'ad', 'bolge_id', 'aktif', 'uye_id'])
            ->map(static fn ($e) => [
                'id' => (int) $e->id, 'ad' => (string) $e->ad, 'bolge' => (string) ($bolgeler[$e->bolge_id] ?? ''), 'aktif' => (bool) $e->aktif,
                'uye_id' => $e->uye_id ? (int) $e->uye_id : null,
                'uye' => $e->uye_id ? ($uyeAdi[(int) $e->uye_id]['name'] ?? (string) (DB::table('members')->where('id', $e->uye_id)->value('name') ?? 'silinmiş hesap')) : null,
            ])->all();

        return [
            'mazeretler' => $mazeretler,
            'takaslar' => (new Takaslar)->liste(),
            'takas_onayi' => Takaslar::onayGerekir(),
            'eczaneler' => $eczaneler,
            'uyeler' => array_map(static fn (array $u) => ['id' => $u['member_id'], 'ad' => $u['name'], 'eposta' => $u['email'], 'rol' => $u['role']], $uyeler),
        ];
    }
}
