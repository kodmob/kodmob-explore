<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Settings\SettingsService;
use App\Core\Support\SiteManifest;
use Illuminate\Support\Facades\DB;
use Kodmob\Members\Organizations;
use Kodmob\Members\UsersToMembers;
use KodmobTools\Nobet\NobetModule;

/**
 * Eczacıların panel hesabından site üyesine geçişi (`php artisan nobet:uyelere`; Kodmob 3.2 üye paneli):
 *
 * 1. Kurumlar açılır (`members.orgs` kapalıysa "yalnızca site yönetimi açar"), oda bir kurum olur (ayar `nobet.kurum`;
 *    yoksa sitenin adıyla açılır).
 * 2. Eczacı rolündeki hesaplar üye olur ve odaya üye olarak katılır (çatının `UsersToMembers`'ı: aynı şifre, aynı
 *    e-posta). `--pasif` ile panel hesapları pasife alınır.
 * 3. Oda yöneticileri odaya yönetici olarak katılır; panel hesapları açık kalır (planlama yönetim arayüzünde).
 * 4. Eczanenin hesabı (`kullanici_id`) ve mazeret taleplerinin bildireni e-postayla üyeye bağlanır (`uye_id`).
 *
 * İkinci kez çalışması zararsızdır: var olan üye, kurum üyeliği ve bağ yeniden yazılmaz.
 */
final class UyeGecisi
{
    /** @return array{kurum: array{id: int, name: string}, eczacilar: array<string, mixed>, yoneticiler: array<string, mixed>, eczane_bagi: int, mazeret_bagi: int, uyarilar: list<string>} */
    public static function calistir(?string $odaAdi = null, bool $pasif = false, bool $deneme = false): array
    {
        if (! Oda::uyelikAcik()) {
            throw new \RuntimeException('Site üyeliği modülü bu sitede kapalı (profilin modules listesine members ekleyin).');
        }
        $ayar = SettingsService::instance();
        DB::beginTransaction();
        try {
            if (! Organizations::enabled()) {
                $ayar->set(Organizations::SETTING, 'admins');
            }
            $kurumId = $ayar->int(NobetModule::KURUM);
            $kurum = $kurumId > 0 ? Organizations::find($kurumId) : null;
            if ($kurum === null) {
                $kurum = Organizations::create(trim((string) $odaAdi) !== '' ? (string) $odaAdi : SiteManifest::siteName());
                $ayar->set(NobetModule::KURUM, (int) $kurum['id']);
            }
            $eczacilar = UsersToMembers::run([Uyeler::ECZACI], (int) $kurum['id'], Organizations::MEMBER, $pasif);
            $yoneticiler = UsersToMembers::run([Uyeler::YONETICI], (int) $kurum['id'], Organizations::MANAGER);

            // Panel hesabı → üye (e-postayla).
            $uyeler = DB::table('members')->pluck('id', 'email')->mapWithKeys(fn ($id, $e) => [mb_strtolower((string) $e) => (int) $id])->all();
            $hesaplar = DB::table('users')->pluck('email', 'id')->map(fn ($e) => $uyeler[mb_strtolower((string) $e)] ?? null)->filter()->all();
            $eczaneBagi = 0;
            $uyarilar = [];
            foreach (DB::table(Eczaneler::TABLO)->whereNotNull('kullanici_id')->whereNull('uye_id')->get(['id', 'ad', 'kullanici_id']) as $e) {
                $uye = $hesaplar[(int) $e->kullanici_id] ?? null;
                if ($uye === null) {
                    $uyarilar[] = "{$e->ad}: bağlı hesabın üyesi yok (e-posta yok ya da hesap eczacı değil)";
                } elseif (DB::table(Eczaneler::TABLO)->where('uye_id', $uye)->exists()) {
                    $uyarilar[] = "{$e->ad}: hesabın üyesi başka bir eczaneye bağlı";
                } else {
                    DB::table(Eczaneler::TABLO)->where('id', $e->id)->update(['uye_id' => $uye]);
                    $eczaneBagi++;
                }
            }
            $mazeretBagi = 0;
            foreach (DB::table(Mazeretler::TABLO)->whereNotNull('olusturan_id')->whereNull('uye_id')->where('durum', '!=', Mazeretler::KAYIT)->get(['id', 'olusturan_id']) as $m) {
                if ($uye = $hesaplar[(int) $m->olusturan_id] ?? null) {
                    DB::table(Mazeretler::TABLO)->where('id', $m->id)->update(['uye_id' => $uye]);
                    $mazeretBagi++;
                }
            }
            $deneme ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'kurum' => ['id' => (int) $kurum['id'], 'name' => (string) $kurum['name']], 'eczacilar' => $eczacilar, 'yoneticiler' => $yoneticiler,
            'eczane_bagi' => $eczaneBagi, 'mazeret_bagi' => $mazeretBagi, 'uyarilar' => array_merge($uyarilar, $eczacilar['skipped'], $yoneticiler['skipped']),
        ];
    }
}
