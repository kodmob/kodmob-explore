<?php

namespace KodmobTools\Nobet\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use App\Core\Security\Roles;
use Illuminate\Http\Request;
use KodmobTools\Nobet\Veri\Uyeler;

/**
 * Yönetim arayüzünün Üyeler sayfası (eski `uyeler` tablosu → Kodmob kullanıcıları; Veri\Uyeler):
 *
 *   GET    /admin/nobet/eski/uyeler          km_view_users
 *   GET    /admin/nobet/eski/uyeler/{id}     km_view_users
 *   POST   /admin/nobet/eski/uyeler          km_manage_users   { adi, eposta, telefon, sifre, rol, kullanici? }
 *   PUT    /admin/nobet/eski/uyeler/{id}     km_manage_users   kısmi; aktivasyon 0/1 pasife alır / açar
 *   DELETE /admin/nobet/eski/uyeler/{id}     km_manage_users   pasife alır
 *
 * İşlem Geçmişi'ne Kodmob'un kullanıcı olaylarıyla yazılır (users.created, users.updated, users.deactivated…).
 */
final class UyelerController extends Controller
{
    public function liste(): array
    {
        return (new Uyeler)->liste();
    }

    public function bul(int $id): array
    {
        return (new Uyeler)->bul($id);
    }

    public function ekle(Request $request): array
    {
        return $this->kaydet($request, null);
    }

    public function guncelle(Request $request, int $id): array
    {
        return $this->kaydet($request, $id);
    }

    public function sil(Request $request, int $id): array
    {
        $uyeler = new Uyeler;
        $kullanici = $uyeler->sil($id, $request->user());
        Activity::type('users.deactivated');
        Activity::subject('user', (int) $kullanici->id, (string) $kullanici->display_name);

        return ['id' => $id, 'deleted' => true] + $uyeler->satir($kullanici);
    }

    private function kaydet(Request $request, ?int $id): array
    {
        $govde = $request->input('body', $request->except(['body']));
        $uyeler = new Uyeler;
        [$kullanici, $tur, $degisen] = $uyeler->kaydet($id, is_array($govde) ? $govde : [], $request->user());
        Activity::type($tur);
        Activity::subject('user', (int) $kullanici->id, (string) $kullanici->display_name);
        Activity::with($tur === 'users.created'
            ? ['role' => Roles::get((string) $kullanici->role)['label'] ?? (string) $kullanici->role, 'fields' => []]
            : ['changes' => $degisen, 'fields' => []]);

        return $uyeler->satir($kullanici);
    }
}
