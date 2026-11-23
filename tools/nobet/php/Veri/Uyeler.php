<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use App\Modules\Auth\Models\User;
use App\Modules\Users\UserService;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\NobetModule;

/**
 * Eski `uyeler` tablosu → Kodmob kullanıcıları (her oda kendi veritabanında, kullanıcıları da odaya özel).
 *
 * - Yalnızca iki rol: Oda yöneticisi (eski `rol = 1`) ve Eczacı (diğer her değer). Yönetici ve öbür Kodmob rolleri
 *   bu listede görünmez, buradan düzenlenmez (sistem panelinden yönetilir).
 * - Yazmalar Kodmob'un kullanıcı servisinden geçer: şifre politikası, benzersizlik, "Yönetici rolünü yalnızca
 *   Yönetici verir" kuralı, şifre değişince oturumların kapanması.
 * - Silme pasife almadır: İşlem Geçmişi'nde adı kalır; `aktivasyon: 1` ile yeniden açılır.
 * - Eski sütunlar: adi → display_name, eposta → email, telefon → phone, sifre → password, aktivasyon → status.
 *   `kullanici` (giriş adı) yeni; verilmezse e-postadan türetilir. Giriş e-postayla da yapılabilir.
 */
final class Uyeler
{
    public const YONETICI = 'oda-yoneticisi';

    public const ECZACI = 'eczaci';

    /** @return array<string, array{label: string, description: string, caps: list<string>}> */
    public static function rolTanimlari(): array
    {
        return [
            self::YONETICI => [
                'label' => 'Oda yöneticisi',
                'description' => 'Nöbet listesini, eczaneleri ve odanın üyelerini yönetir.',
                'caps' => [Capability::ACCESS_PANEL, NobetModule::GORME, NobetModule::YONETME, Capability::VIEW_USERS, Capability::MANAGE_USERS],
            ],
            self::ECZACI => [
                'label' => 'Eczacı',
                'description' => 'Nöbet listesini ve takvimi görür.',
                'caps' => [Capability::ACCESS_PANEL, NobetModule::GORME],
            ],
        ];
    }

    /** İki rol yoksa oluşturur (var olanın yetkilerine dokunmaz: sistem yöneticisi değiştirmiş olabilir). */
    public static function roller(): void
    {
        foreach (self::rolTanimlari() as $slug => $tanim) {
            if (DB::table('roles')->where('slug', $slug)->exists()) {
                continue;
            }
            $simdi = Clock::now();
            DB::table('roles')->insert([
                'slug' => $slug,
                'label' => $tanim['label'],
                'description' => $tanim['description'],
                'caps' => Text::json($tanim['caps']),
                'menu' => null,
                'system' => false,
                'created_at' => $simdi,
                'updated_at' => $simdi,
            ]);
        }
        Roles::forget();
    }

    private readonly UserService $servis;

    public function __construct(?UserService $servis = null)
    {
        $this->servis = $servis ?? new UserService;
    }

    /** @return list<array<string, mixed>> */
    public function liste(): array
    {
        return User::query()->whereIn('role', [self::YONETICI, self::ECZACI])->orderBy('id')->get()
            ->map(fn (User $u) => $this->satir($u))->all();
    }

    /** @return array<string, mixed> */
    public function bul(int $id): array
    {
        return $this->satir($this->kullanici($id));
    }

    /**
     * Ekle (id null) ya da kısmi güncelle. Dönüş: [kullanıcı, olay türü, değişiklikler].
     *
     * @return array{0: User, 1: string, 2: array<string, mixed>}
     */
    public function kaydet(?int $id, array $govde, User $yapan): array
    {
        self::roller();
        $girdi = [];
        foreach (['adi' => 'display_name', 'eposta' => 'email', 'telefon' => 'phone', 'kullanici' => 'username', 'sifre' => 'password'] as $eski => $yeni) {
            if (array_key_exists($eski, $govde) && $govde[$eski] !== null) {
                $girdi[$yeni] = trim((string) $govde[$eski]);
            }
        }
        if (array_key_exists('rol', $govde)) {
            $girdi['role'] = self::rol($govde['rol']);
        }

        if ($id === null) {
            $girdi += ['role' => self::ECZACI, 'phone' => ''];
            if (($girdi['username'] ?? '') === '') {
                $girdi['username'] = self::kullaniciAdi((string) ($girdi['email'] ?? ''), (string) ($girdi['display_name'] ?? ''));
            }
            $kullanici = DB::transaction(function () use ($girdi, $yapan, $govde) {
                $k = $this->servis->create($girdi, $yapan);
                if (array_key_exists('eczane_id', $govde)) {
                    self::eczaneBagla((int) $k->id, $govde['eczane_id']);
                }

                return $k;
            });
            if (isset($govde['aktivasyon']) && ! self::acik($govde['aktivasyon'])) {
                $this->servis->deactivate($kullanici, $yapan);
            }

            return [$kullanici->refresh(), 'users.created', []];
        }

        $kullanici = $this->kullanici($id);
        $degisen = [];
        if (array_diff_key($girdi, ['password' => 1]) !== [] || ($girdi['password'] ?? '') !== '') {
            $degisen = $this->servis->update($kullanici, $girdi, $yapan);
        }
        $tur = 'users.updated';
        if (array_key_exists('eczane_id', $govde)) {
            $onceki = DB::table(Eczaneler::TABLO)->where('kullanici_id', $kullanici->id)->value('ad');
            self::eczaneBagla((int) $kullanici->id, $govde['eczane_id']);
            $sonraki = DB::table(Eczaneler::TABLO)->where('kullanici_id', $kullanici->id)->value('ad');
            if ($onceki !== $sonraki) {
                $degisen['eczane'] = ['label' => 'Eczane', 'from' => (string) $onceki, 'to' => (string) $sonraki];
            }
        }
        if (array_key_exists('aktivasyon', $govde) && $govde['aktivasyon'] !== null && $govde['aktivasyon'] !== '') {
            $acik = self::acik($govde['aktivasyon']);
            if ($acik !== $kullanici->isActive()) {
                $acik ? $this->servis->activate($kullanici, $yapan) : $this->servis->deactivate($kullanici, $yapan);
                $tur = $degisen === [] ? ($acik ? 'users.activated' : 'users.deactivated') : $tur;
            }
        }

        return [$kullanici->refresh(), $tur, $degisen];
    }

    /** Silme = pasife alma. */
    public function sil(int $id, User $yapan): User
    {
        $kullanici = $this->kullanici($id);
        $this->servis->deactivate($kullanici, $yapan);

        return $kullanici->refresh();
    }

    /** @return array<string, mixed> eski satır biçimi */
    public function satir(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'adi' => (string) $u->display_name,
            'kullanici' => (string) $u->username,
            'eposta' => (string) $u->email,
            'telefon' => (string) $u->phone,
            'rol' => $u->role === self::YONETICI ? 1 : 0,
            'rol_adi' => Roles::get((string) $u->role)['label'] ?? (string) $u->role,
            'aktivasyon' => $u->isActive() ? 1 : 0,
            'tarih' => Clock::local($u->getRawOriginal('created_at')),
            'eczane_id' => ($e = DB::table(Eczaneler::TABLO)->where('kullanici_id', $u->id)->first(['id', 'ad'])) ? (int) $e->id : null,
            'eczane_adi' => $e->ad ?? null,
        ];
    }

    /**
     * Hesabı bir eczaneye bağlar (bir kullanıcı bir eczane, bir eczane bir kullanıcı); boş ya da 0 bağı kaldırır.
     * Eczanenin önceki kullanıcısıyla bağı kopar.
     */
    public static function eczaneBagla(int $kullanici, mixed $eczane): void
    {
        $eczane = Kayit::bosIse(filter_var($eczane, FILTER_VALIDATE_INT) ?: null);
        if ($eczane !== null && ! DB::table(Eczaneler::TABLO)->where('id', $eczane)->exists()) {
            throw ValidationException::field('eczane_id', 'Eczane bulunamadı.');
        }
        DB::transaction(function () use ($kullanici, $eczane) {
            DB::table(Eczaneler::TABLO)->where('kullanici_id', $kullanici)->update(['kullanici_id' => null]);
            if ($eczane !== null) {
                DB::table(Eczaneler::TABLO)->where('id', $eczane)->update(['kullanici_id' => $kullanici]);
            }
        });
    }

    /** Eski `rol`: 1 (ya da rolün adı) yönetici, başka her şey eczacı. */
    public static function rol(mixed $deger): string
    {
        $deger = (string) $deger;

        return in_array($deger, ['1', self::YONETICI], true) ? self::YONETICI : self::ECZACI;
    }

    private static function acik(mixed $deger): bool
    {
        return in_array((string) $deger, ['1', 'true', 'aktif', 'active'], true);
    }

    /** E-postanın @ öncesi (yoksa ad), [a-z0-9._-], 3-40 karakter, boşta olan ilk ad. */
    public static function kullaniciAdi(string $eposta, string $ad): string
    {
        $kaynak = str_contains($eposta, '@') ? strstr($eposta, '@', true) : $ad;
        $parcalar = array_filter(array_map(static fn (string $p) => Text::slug($p), preg_split('/[.\s_]+/', $kaynak) ?: []));
        $temel = trim((string) preg_replace('/[^a-z0-9._-]+/', '', implode('.', $parcalar)), '._-');
        $temel = substr($temel !== '' ? $temel : 'uye', 0, 34);
        $temel = str_pad($temel, 3, '0');
        $ad = $temel;
        for ($n = 2; User::query()->where('username', $ad)->exists(); $n++) {
            $ad = $temel.$n;
        }

        return $ad;
    }

    private function kullanici(int $id): User
    {
        $u = User::query()->whereKey($id)->whereIn('role', [self::YONETICI, self::ECZACI])->first();

        return $u ?? throw NotFoundException::message('Üye bulunamadı.');
    }
}
