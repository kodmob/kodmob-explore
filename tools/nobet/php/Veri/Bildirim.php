<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Security\Roles;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Kodmob\Members\MemberNotifications;
use KodmobTools\Nobet\NobetModule;

/**
 * Nöbet bildirimleri ve e-postaları (SendMail, arka planda; gönderilemezse yeniden denenir, işi bozmaz). Alıcı iki
 * türlüdür:
 *
 * - Panel hesabı (yönetim arayüzü `/admin/`): zil NotificationService; bağlantı yönetim arayüzüne.
 * - Site üyesi (Hesabım, Kodmob 3.2): zil + telefona anlık bildirim MemberNotifications; bağlantı Hesabım'ın sayfasına
 *   (Nöbetlerim `nobet-nobetlerim`, Oda yönetimi `nobet-oda`).
 *
 * Olaylar: yeni mazeret talebi ve onay bekleyen takas → nöbeti yönetenlere (`km_manage_nobet` panel hesapları ve odanın
 * kurumundaki yöneticiler); karar → talebi gönderen eczaneye; takas teklifi → karşı eczaneye; son döngünün bitişi
 * yaklaşıyor → nöbeti yönetenlere (Donguler::hatirlat, günlük görev).
 */
final class Bildirim
{
    private const NOBETLERIM = 'nobet-nobetlerim';

    private const ODA = 'nobet-oda';

    public static function talepGeldi(int $mazeretId, string $eczane, string $tarih, string $aciklama): void
    {
        $baslik = 'Mazeret talebi: '.$eczane;
        $mesaj = self::tarih($tarih).' · '.mb_substr(trim($aciklama), 0, 160);
        self::yonetenlere('nobet.mazeret_talebi', $baslik, $mesaj, '/admin/mazeret', 'warning', ['mazeret_id' => $mazeretId], [
            $eczane.' eczanesi '.self::tarih($tarih).' günü için mazeret bildirdi. Talebi onaylayabilir ya da reddedebilirsiniz.',
            trim($aciklama),
        ], 'Talebe git');
    }

    public static function kararVerildi(int $mazeretId, string $karar, string $tarih, ?string $not): void
    {
        $m = DB::table(Mazeretler::TABLO)->where('id', $mazeretId)->first();
        if ($m === null) {
            return;
        }
        $onay = $karar === 'onay';
        $baslik = 'Mazeret talebiniz '.($onay ? 'onaylandı' : 'reddedildi');
        $mesaj = self::tarih($tarih).($not ? ' · '.mb_substr($not, 0, 160) : '');
        $satirlar = array_values(array_filter([
            self::tarih($tarih).' günü için bildirdiğiniz mazeret '.($onay ? 'onaylandı. Nöbet değişikliğini oda yönetimi yapar; güncel listeyi Nöbetlerim sayfasında görebilirsiniz.' : 'reddedildi.'),
            $not ? 'Oda yönetiminin notu: '.$not : null,
        ]));
        $veri = ['mazeret_id' => $mazeretId];
        // Bildiren üye; yoksa bildiren panel hesabı; o da yoksa eczanenin hesabı.
        if ($m->uye_id ?? null) {
            self::uyeye((int) $m->uye_id, 'nobet.mazeret_karari', $baslik, $mesaj, self::NOBETLERIM, $satirlar);
        } elseif ($m->olusturan_id) {
            self::kullaniciya((int) $m->olusturan_id, 'nobet.mazeret_karari', $baslik, $mesaj, '/admin/nobetlerim', $onay ? 'success' : 'danger', $veri, $satirlar);
        } elseif ($m->eczane_id) {
            self::eczaneye((int) $m->eczane_id, 'nobet.mazeret_karari', $baslik, $mesaj, $onay ? 'success' : 'danger', $veri, $satirlar);
        }
    }

    /** Takas teklifi → karşı eczaneye. */
    public static function takasTeklifi(object $t): void
    {
        $s = (new Takaslar)->satir($t);
        self::eczaneye($s['karsi_eczane_id'], 'nobet.takas_teklifi', 'Nöbet takası teklifi: '.$s['eczane'],
            self::tarih($s['karsi_tarih']).' nöbetiniz ↔ '.self::tarih($s['tarih']), 'warning', ['takas_id' => $s['id']], array_values(array_filter([
                $s['eczane'].' eczanesi '.self::tarih($s['karsi_tarih']).' '.$s['karsi_gun_adi'].' nöbetinizi kendi '.self::tarih($s['tarih']).' '.$s['gun_adi'].' nöbetiyle değiştirmeyi öneriyor.',
                $s['aciklama'] !== '' ? 'Açıklama: '.$s['aciklama'] : null,
                'Teklifi Nöbetlerim sayfasından kabul edebilir ya da reddedebilirsiniz.',
            ])));
    }

    /** Karşı eczanenin yanıtı → teklif edene; kabulde (onay gerekiyorsa) nöbeti yönetenlere de. */
    public static function takasYaniti(object $t): void
    {
        $s = (new Takaslar)->satir($t);
        $kabul = $s['durum'] === Takaslar::KABUL;
        self::eczaneye($s['eczane_id'], 'nobet.takas_yaniti', 'Takas teklifiniz '.($kabul ? 'kabul edildi' : 'reddedildi'),
            $s['karsi_eczane'].' · '.self::tarih($s['tarih']).' ↔ '.self::tarih($s['karsi_tarih']), $kabul ? 'success' : 'danger', ['takas_id' => $s['id']], [
                $s['karsi_eczane'].' eczanesi takas teklifinizi '.($kabul ? 'kabul etti. Nöbetler oda yönetiminin onayından sonra değişir.' : 'reddetti.'),
            ]);
        if ($kabul) {
            self::yonetenlere('nobet.takas_onayi', 'Onay bekleyen takas: '.$s['eczane'].' ↔ '.$s['karsi_eczane'],
                self::tarih($s['tarih']).' ↔ '.self::tarih($s['karsi_tarih']), '/admin/mazeret', 'warning', ['takas_id' => $s['id']], array_values(array_filter([
                    $s['eczane'].' ('.self::tarih($s['tarih']).') ile '.$s['karsi_eczane'].' ('.self::tarih($s['karsi_tarih']).') nöbetlerini değiştirmek istiyor; iki eczane de kabul etti.',
                    $s['aciklama'] !== '' ? 'Açıklama: '.$s['aciklama'] : null,
                    'Onaylarsanız iki nöbetin eczanesi yer değiştirir.',
                ])), 'Talebe git');
        }
    }

    /** Takas sonuçlandı (onay ya da yönetimin reddi) → iki eczaneye. */
    public static function takasKarari(object $t): void
    {
        $s = (new Takaslar)->satir($t);
        $onay = $s['durum'] === Takaslar::ONAY;
        $baslik = 'Nöbet takası '.($onay ? 'onaylandı' : 'reddedildi');
        $mesaj = $s['eczane'].' '.self::tarih($s['tarih']).' ↔ '.$s['karsi_eczane'].' '.self::tarih($s['karsi_tarih']);
        $satirlar = array_values(array_filter([
            $onay ? 'Takas uygulandı: '.$s['eczane'].' eczanesi '.self::tarih($s['karsi_tarih']).', '.$s['karsi_eczane'].' eczanesi '.self::tarih($s['tarih']).' günü nöbetçi.'
                : $mesaj.' takası oda yönetimince reddedildi.',
            $s['karar_notu'] ? 'Oda yönetiminin notu: '.$s['karar_notu'] : null,
        ]));
        foreach ([$s['eczane_id'], $s['karsi_eczane_id']] as $eczane) {
            self::eczaneye($eczane, 'nobet.takas_karari', $baslik, $mesaj, $onay ? 'success' : 'danger', ['takas_id' => $s['id']], $satirlar);
        }
    }

    /** Son döngünün bitişine az kaldı (Donguler::hatirlat). */
    public static function donguBitiyor(string $sonGun, int $kalanGun, int $esik): void
    {
        self::yonetenlere('nobet.dongu_bitiyor', 'Nöbet listesi '.self::tarih($sonGun).' tarihinde bitiyor',
            ($kalanGun === 0 ? 'Bugün son gün.' : $kalanGun.' gün kaldı.').' Yeni döngüyü Döngüler sayfasından oluşturabilirsiniz.', '/admin/donguler', 'warning', ['son_gun' => $sonGun, 'esik' => $esik], [
                'Oluşturulmuş son nöbet günü '.self::tarih($sonGun).'. Bu tarihten sonra nöbet listesi boş ('.$kalanGun.' gün kaldı).',
                'Yeni döngüyü yönetim arayüzünün Döngüler sayfasından oluşturabilirsiniz.',
            ], 'Döngülere git', false);
    }

    /**
     * Nöbeti yönetenlere zil + e-posta: `km_manage_nobet` yetkili etkin panel hesapları ve (`$odaya`) odanın kurumundaki
     * yöneticiler (Hesabım › Oda yönetimi). Aynı e-posta iki kez yazılmaz.
     *
     * @param  list<string>  $satirlar
     */
    private static function yonetenlere(string $tur, string $baslik, string $mesaj, string $adres, string $ton, array $veri, array $satirlar, string $dugme, bool $odaya = true): void
    {
        $yazilan = [];
        $roller = array_keys(array_filter(Roles::all(), static fn (array $r) => in_array(NobetModule::YONETME, $r['caps'], true)));
        if ($roller !== []) {
            $servis = new NotificationService;
            foreach (DB::table('users')->whereIn('role', $roller)->where('status', 'active')->get(['id', 'email']) as $kisi) {
                $servis->create((int) $kisi->id, $tur, $baslik, $mesaj, $adres, $ton, $veri);
                $yazilan[mb_strtolower((string) $kisi->email)] = true;
                self::eposta((string) $kisi->email, $baslik, array_values(array_filter($satirlar)), $adres, $dugme, $tur);
            }
        }
        if (! $odaya) {
            return;
        }
        foreach (Oda::yoneticiler() as $uye) {
            $eposta = mb_strtolower((string) DB::table('members')->where('id', $uye)->value('email'));
            MemberNotifications::notify($uye, $tur, $baslik, $mesaj, Oda::sayfa(self::ODA));
            if (! isset($yazilan[$eposta])) {
                $yazilan[$eposta] = true;
                self::eposta($eposta, $baslik, array_values(array_filter($satirlar)), Oda::sayfa(self::ODA), 'Oda yönetimi', $tur);
            }
        }
    }

    /**
     * Eczanenin hesabına: site üyesi (`uye_id`) varsa ona, yoksa panel hesabına (`kullanici_id`).
     *
     * @param  list<string>  $satirlar
     */
    private static function eczaneye(int $eczaneId, string $tur, string $baslik, string $mesaj, string $ton, array $veri, array $satirlar): void
    {
        $e = DB::table(Eczaneler::TABLO)->where('id', $eczaneId)->first(['uye_id', 'kullanici_id']);
        if ($e === null) {
            return;
        }
        if (($e->uye_id ?? null) && Oda::uyelikAcik()) {
            self::uyeye((int) $e->uye_id, $tur, $baslik, $mesaj, self::NOBETLERIM, $satirlar);
        } elseif ($e->kullanici_id) {
            self::kullaniciya((int) $e->kullanici_id, $tur, $baslik, $mesaj, '/admin/nobetlerim', $ton, $veri, $satirlar);
        }
    }

    /** @param list<string> $satirlar */
    private static function uyeye(int $uyeId, string $tur, string $baslik, string $mesaj, string $sayfa, array $satirlar): void
    {
        if (! Oda::uyelikAcik()) {
            return;
        }
        $eposta = DB::table('members')->where('id', $uyeId)->where('status', 'active')->value('email');
        if ($eposta === null) {
            return;
        }
        MemberNotifications::notify($uyeId, $tur, $baslik, $mesaj, Oda::sayfa($sayfa));
        self::eposta((string) $eposta, $baslik, $satirlar, Oda::sayfa($sayfa), 'Nöbetlerim', $tur);
    }

    /** @param list<string> $satirlar */
    private static function kullaniciya(int $kullaniciId, string $tur, string $baslik, string $mesaj, string $adres, string $ton, array $veri, array $satirlar): void
    {
        $kisi = DB::table('users')->where('id', $kullaniciId)->where('status', 'active')->first(['id', 'email']);
        if ($kisi === null) {
            return;
        }
        (new NotificationService)->create((int) $kisi->id, $tur, $baslik, $mesaj, $adres, $ton, $veri);
        self::eposta((string) $kisi->email, $baslik, $satirlar, $adres, 'Nöbetlerim', $tur);
    }

    /** @param list<string> $satirlar */
    private static function eposta(string $kime, string $konu, array $satirlar, string $adres, string $dugme, string $tur): void
    {
        if (! Text::isEmail($kime)) {
            return;
        }
        SendMail::to($kime, new SiteMail(
            '['.SiteManifest::siteName().'] '.$konu,
            $satirlar,
            action: ['label' => $dugme, 'url' => rtrim((string) config('app.url'), '/').$adres],
            mailLocale: 'tr',
        ), $tur);
    }

    private static function tarih(string $tarih): string
    {
        $p = explode('-', substr($tarih, 0, 10));

        return count($p) === 3 ? $p[2].'.'.$p[1].'.'.$p[0] : $tarih;
    }
}
