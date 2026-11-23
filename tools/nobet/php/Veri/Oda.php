<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\DomainException;
use App\Core\Modules;
use App\Core\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kodmob\Members\Members;
use Kodmob\Members\Organizations;
use KodmobTools\Nobet\NobetModule;

/**
 * Odanın üye tarafı (Kodmob 3.2 üye paneli): eczacılar site üyesidir (Site üyeliği modülü), oda bu sitedeki bir kurumdur
 * (ayar `nobet.kurum`). Kurumun yöneticisi ya da sahibi Hesabım › Oda yönetimi'ni görür: mazeret ve takas kararları,
 * eczane ↔ hesap bağı. Döngü, eczane, bölge gibi planlama işleri yönetim arayüzünde (`/admin/`) kalır.
 */
final class Oda
{
    /** Üye paneli bu sitede açık mı (Site üyeliği modülü ve tabloları). */
    public static function uyelikAcik(): bool
    {
        return Modules::enabled('members') && class_exists(Members::class) && Schema::hasTable('members');
    }

    /** Odanın kurumu (yoksa 0). */
    public static function kurum(): int
    {
        return self::uyelikAcik() ? SettingsService::instance()->int(NobetModule::KURUM) : 0;
    }

    /** Oturumdaki üye (yoksa 401; üyelik kapalıysa 404). @return array<string, mixed> */
    public static function uye(Request $request): array
    {
        if (! self::uyelikAcik()) {
            throw new DomainException('km_not_found', 'Bu özellik bu sitede kapalı.', 404);
        }

        return Members::current($request) ?? throw new DomainException('km_member_unauthenticated', 'Oturumunuz kapanmış. Yeniden giriş yapın.', 401);
    }

    /** Üye odanın yöneticisi ya da sahibi mi (kurumlar açık, kurum ayarlı). */
    public static function yoneticiMi(int $uyeId): bool
    {
        $kurum = self::kurum();

        return $kurum > 0 && Organizations::enabled() && Organizations::atLeast(Organizations::role($kurum, $uyeId), Organizations::MANAGER);
    }

    /** Oda yönetimi ucu: üye odanın yöneticisi değilse 403. @return array<string, mixed> üye */
    public static function yonetici(Request $request): array
    {
        $uye = self::uye($request);
        if (! self::yoneticiMi((int) $uye['id'])) {
            throw new DomainException('nobet_oda_yetki', 'Bu sayfa oda yönetimi içindir.', 403);
        }

        return $uye;
    }

    /** Odanın yöneticileri ve sahipleri (bildirim için). @return list<int> */
    public static function yoneticiler(): array
    {
        $kurum = self::kurum();
        if ($kurum === 0 || ! Organizations::enabled()) {
            return [];
        }

        return DB::table('member_org_members')->where('org_id', $kurum)->whereIn('role', [Organizations::OWNER, Organizations::MANAGER])
            ->pluck('member_id')->map('intval')->all();
    }

    /** Üyenin eczanesi (yoksa null). */
    public static function uyeninEczanesi(int $uyeId): ?object
    {
        return DB::table(Eczaneler::TABLO)->where('uye_id', $uyeId)->first();
    }

    /** Hesabım'daki sayfanın adresi (`?sayfa=`); site adresi olmadan, bildirimde ve e-postada. */
    public static function sayfa(string $anahtar): string
    {
        $url = self::uyelikAcik() ? Members::pageUrl('tr', ['sayfa' => $anahtar]) : '/hesap/?sayfa='.$anahtar;
        $yol = parse_url($url, PHP_URL_PATH) ?: '/hesap/';
        $sorgu = parse_url($url, PHP_URL_QUERY);

        return $yol.($sorgu ? '?'.$sorgu : '');
    }
}
