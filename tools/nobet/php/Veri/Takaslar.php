<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Takvim;
use KodmobTools\Nobet\NobetModule;

/**
 * Nöbet takası: eczacı gelecek bir nöbetini aynı bölgedeki başka bir eczanenin gelecek nöbetiyle değiştirmeyi önerir.
 *
 *   teklif  karşı eczane yanıt verecek (kabul | ret); teklif eden geri alabilir (iptal)
 *   kabul   oda yönetiminin onayı bekleniyor (ayar `nobet.takas_onayi` kapalıysa kabulde hemen uygulanır)
 *   onay    nöbetler değişti (iki kaydın eczanesi yer değiştirir; kaynak "elle", gerekçede takasın kimliği)
 *   ret     karşı eczane ya da oda yönetimi reddetti (`ret_eden`)
 *   iptal   teklif eden geri aldı ya da nöbetlerden biri bu arada değişti
 *
 * Kurallar sunucuda: iki nöbet de gelecekte, aynı bölge ve vardiya, farklı gün; takastan sonra bir eczane aynı gün iki
 * kez nöbetçi olmaz; bir nöbet aynı anda tek açık takasta. Motorun adalet kuralları yeniden denetlenmez (yönetim onaylar).
 */
final class Takaslar
{
    public const TABLO = 'nobet_takaslar';

    public const TEKLIF = 'teklif';

    public const KABUL = 'kabul';

    public const ONAY = 'onay';

    public const RET = 'ret';

    public const IPTAL = 'iptal';

    /** Açık (sonuçlanmamış) durumlar. */
    public const ACIK = [self::TEKLIF, self::KABUL];

    /** Takas en çok bu kadar gün ilerisi için. */
    private const EN_ILERI = 366;

    /**
     * Eczanenin bu nöbetiyle takas edilebilecek nöbetler: aynı bölge ve vardiyada, başka günde, başka (etkin) eczanenin
     * gelecek nöbeti; takastan sonra iki eczane de o gün tek kez nöbetçi.
     *
     * @return list<array{nobet_id: int, tarih: string, gun_adi: string, eczane_id: int, eczane: string}>
     */
    public function adaylar(object $eczane, int $nobetId): array
    {
        $nobet = $this->kendiNobeti($eczane, $nobetId);
        $tarih = substr((string) $nobet->tarih, 0, 10);
        $acik = $this->acikNobetler();
        $gunler = [];
        foreach (DB::table(Nobetler::TABLO)->where('tarih', '>=', $this->bugun())->get(['eczane_id', 'tarih']) as $n) {
            $gunler[(int) $n->eczane_id][substr((string) $n->tarih, 0, 10)] = true;
        }

        return DB::table(Nobetler::TABLO.' as n')->join(Eczaneler::TABLO.' as e', 'e.id', '=', 'n.eczane_id')
            ->where('n.bolge_id', $nobet->bolge_id)->where('n.vardiya', $nobet->vardiya)->where('n.eczane_id', '!=', $eczane->id)
            ->where('e.aktif', true)->where('n.tarih', '>=', $this->bugun())->where('n.tarih', '<=', $this->sonGun())
            ->orderBy('n.tarih')->orderBy('e.ad')->get(['n.id', 'n.tarih', 'n.eczane_id', 'e.ad'])
            ->filter(static function ($n) use ($tarih, $acik, $gunler, $eczane) {
                $gun = substr((string) $n->tarih, 0, 10);

                // Takastan sonra: bu eczane o gün, karşı eczane bu nöbetin günü zaten nöbetçi olmasın.
                return $gun !== $tarih && ! isset($acik[(int) $n->id]) && ! isset($gunler[(int) $eczane->id][$gun])
                    && ! isset($gunler[(int) $n->eczane_id][$tarih]);
            })
            ->map(static fn ($n) => [
                'nobet_id' => (int) $n->id, 'tarih' => substr((string) $n->tarih, 0, 10), 'gun_adi' => Takvim::gun(new \DateTimeImmutable(substr((string) $n->tarih, 0, 10))),
                'eczane_id' => (int) $n->eczane_id, 'eczane' => (string) $n->ad,
            ])->values()->all();
    }

    /**
     * Takas teklifi. Teklif eden panel hesabı (`$kullanici`) ya da üye (`$uye`).
     *
     * @param  array{nobet_id?: mixed, karsi_nobet_id?: mixed, aciklama?: mixed}  $girdi
     * @return array{id: int, ad: string}
     */
    public function teklif(object $eczane, array $girdi, ?int $kullanici, ?int $uye): array
    {
        $nobetId = (int) filter_var($girdi['nobet_id'] ?? 0, FILTER_VALIDATE_INT);
        $karsiId = (int) filter_var($girdi['karsi_nobet_id'] ?? 0, FILTER_VALIDATE_INT);
        $aciklama = mb_substr(trim(strip_tags((string) ($girdi['aciklama'] ?? ''))), 0, 500);
        $this->kendiNobeti($eczane, $nobetId);
        $aday = null;
        foreach ($this->adaylar($eczane, $nobetId) as $a) {
            if ($a['nobet_id'] === $karsiId) {
                $aday = $a;
            }
        }
        if ($aday === null) {
            throw ValidationException::field('karsi_nobet_id', 'Bu nöbetle takas edilemez: aynı bölgeden, başka güne ait, açık takası olmayan bir nöbet seçin.');
        }
        if (isset($this->acikNobetler()[$nobetId])) {
            throw new ConflictException('nobet_takas_var', 'Bu nöbet için açık bir takas talebi var.');
        }
        $nobet = DB::table(Nobetler::TABLO)->where('id', $nobetId)->first();
        $simdi = Clock::now();
        $id = (int) DB::table(self::TABLO)->insertGetId([
            'eczane_id' => (int) $eczane->id, 'nobet_id' => $nobetId, 'tarih' => substr((string) $nobet->tarih, 0, 10),
            'karsi_eczane_id' => $aday['eczane_id'], 'karsi_nobet_id' => $karsiId, 'karsi_tarih' => $aday['tarih'],
            'aciklama' => $aciklama, 'durum' => self::TEKLIF, 'olusturan_id' => $kullanici, 'olusturan_uye_id' => $uye,
            'created_at' => $simdi, 'updated_at' => $simdi,
        ]);
        Bildirim::takasTeklifi($this->bul($id));

        return ['id' => $id, 'ad' => $this->ad($this->bul($id))];
    }

    /**
     * Karşı eczanenin yanıtı: kabul | ret. Kabulde onay gerekmiyorsa (ayar kapalı) nöbetler hemen değişir.
     *
     * @return array{id: int, ad: string, durum: string}
     */
    public function yanit(object $eczane, int $id, string $yanit): array
    {
        $t = $this->bul($id);
        if ((int) $t->karsi_eczane_id !== (int) $eczane->id) {
            throw NotFoundException::message('Takas talebi bulunamadı.');
        }
        if ($t->durum !== self::TEKLIF) {
            throw new ConflictException('nobet_takas_durum', 'Bu talep artık yanıt beklemiyor.');
        }
        if (! in_array($yanit, ['kabul', 'ret'], true)) {
            throw ValidationException::field('yanit', 'Yanıt kabul ya da ret olmalı.');
        }
        $simdi = Clock::now();
        if ($yanit === 'ret') {
            DB::table(self::TABLO)->where('id', $id)->update(['durum' => self::RET, 'ret_eden' => 'eczane', 'yanit_tarihi' => $simdi, 'updated_at' => $simdi]);
            Bildirim::takasYaniti($this->bul($id));

            return ['id' => $id, 'ad' => $this->ad($t), 'durum' => self::RET];
        }
        DB::table(self::TABLO)->where('id', $id)->update(['durum' => self::KABUL, 'yanit_tarihi' => $simdi, 'updated_at' => $simdi]);
        if (! self::onayGerekir()) {
            $this->uygula($this->bul($id));
            Bildirim::takasKarari($this->bul($id));

            return ['id' => $id, 'ad' => $this->ad($t), 'durum' => self::ONAY];
        }
        Bildirim::takasYaniti($this->bul($id));

        return ['id' => $id, 'ad' => $this->ad($t), 'durum' => self::KABUL];
    }

    /** Teklif edenin geri alması (sonuçlanmadıysa). */
    public function iptal(object $eczane, int $id): string
    {
        $t = $this->bul($id);
        if ((int) $t->eczane_id !== (int) $eczane->id) {
            throw NotFoundException::message('Takas talebi bulunamadı.');
        }
        if (! in_array($t->durum, self::ACIK, true)) {
            throw new ConflictException('nobet_takas_durum', 'Sonuçlanmış talep geri alınamaz.');
        }
        DB::table(self::TABLO)->where('id', $id)->update(['durum' => self::IPTAL, 'updated_at' => Clock::now()]);

        return $this->ad($t);
    }

    /**
     * Oda yönetiminin kararı (onay | ret, isteğe bağlı not); yalnızca karşı tarafın kabul ettiği talepte. Karar veren panel
     * hesabı ya da odanın yöneticisi olan üye.
     *
     * @return array{id: int, ad: string, karar: string}
     */
    public function karar(int $id, array $girdi, ?int $kullanici, ?int $uye): array
    {
        $t = $this->bul($id);
        if ($t->durum !== self::KABUL) {
            throw new ConflictException('nobet_takas_durum', $t->durum === self::TEKLIF ? 'Karşı eczane henüz kabul etmedi.' : 'Bu talep karar beklemiyor.');
        }
        $karar = (string) ($girdi['karar'] ?? '');
        if (! in_array($karar, Mazeretler::KARARLAR, true)) {
            throw ValidationException::field('karar', 'Karar onay ya da ret olmalı.');
        }
        $not = mb_substr(trim(strip_tags((string) ($girdi['not'] ?? ''))), 0, 500);
        $kim = ['karar_notu' => $not !== '' ? $not : null, 'karar_veren_id' => $kullanici, 'karar_veren_uye_id' => $uye, 'karar_tarihi' => Clock::now(), 'updated_at' => Clock::now()];
        if ($karar === 'ret') {
            DB::table(self::TABLO)->where('id', $id)->update(['durum' => self::RET, 'ret_eden' => 'yonetim'] + $kim);
        } else {
            DB::table(self::TABLO)->where('id', $id)->update($kim);
            $this->uygula($this->bul($id));
        }
        Bildirim::takasKarari($this->bul($id));

        return ['id' => $id, 'ad' => $this->ad($t), 'karar' => $karar];
    }

    /** Eczanenin gelen ve giden talepleri (yeniden eskiye, en çok 50). @return list<array<string, mixed>> */
    public function eczaneninkiler(int $eczaneId): array
    {
        $satirlar = DB::table(self::TABLO)->where(fn ($q) => $q->where('eczane_id', $eczaneId)->orWhere('karsi_eczane_id', $eczaneId))
            ->orderByDesc('id')->limit(50)->get()->all();

        return array_map(fn ($t) => $this->satir($t) + ['yon' => (int) $t->eczane_id === $eczaneId ? 'giden' : 'gelen'], $satirlar);
    }

    /** Oda yönetiminin listesi: onay bekleyenler önce, sonra yanıt bekleyenler ve son sonuçlananlar. @return list<array<string, mixed>> */
    public function liste(int $sinir = 100): array
    {
        $satirlar = DB::table(self::TABLO)->orderByRaw("CASE durum WHEN 'kabul' THEN 0 WHEN 'teklif' THEN 1 ELSE 2 END")->orderByDesc('id')->limit($sinir)->get()->all();

        return array_map($this->satir(...), $satirlar);
    }

    public static function onayGerekir(): bool
    {
        return SettingsService::instance()->bool(NobetModule::TAKAS_ONAYI);
    }

    /** @return array<string, mixed> */
    public function satir(object $t): array
    {
        $adlar = DB::table(Eczaneler::TABLO)->whereIn('id', [$t->eczane_id, $t->karsi_eczane_id])->pluck('ad', 'id');
        $tarih = substr((string) $t->tarih, 0, 10);
        $karsi = substr((string) $t->karsi_tarih, 0, 10);

        return [
            'id' => (int) $t->id, 'durum' => (string) $t->durum, 'ret_eden' => $t->ret_eden,
            'eczane_id' => (int) $t->eczane_id, 'eczane' => (string) ($adlar[$t->eczane_id] ?? ''), 'tarih' => $tarih, 'gun_adi' => Takvim::gun(new \DateTimeImmutable($tarih)),
            'karsi_eczane_id' => (int) $t->karsi_eczane_id, 'karsi_eczane' => (string) ($adlar[$t->karsi_eczane_id] ?? ''), 'karsi_tarih' => $karsi, 'karsi_gun_adi' => Takvim::gun(new \DateTimeImmutable($karsi)),
            'aciklama' => (string) $t->aciklama, 'karar_notu' => $t->karar_notu,
            'eklenme' => substr((string) $t->created_at, 0, 16), 'karar_tarihi' => $t->karar_tarihi ? substr((string) $t->karar_tarihi, 0, 16) : null,
        ];
    }

    public function bul(int $id): object
    {
        return DB::table(self::TABLO)->where('id', $id)->first() ?? throw NotFoundException::message('Takas talebi bulunamadı.');
    }

    /** "GÜNEŞ 12.12 ↔ YONCA 19.12" */
    public function ad(object $t): string
    {
        $s = $this->satir($t);
        $kisa = static fn (string $tarih) => substr($tarih, 8, 2).'.'.substr($tarih, 5, 2);

        return $s['eczane'].' '.$kisa($s['tarih']).' ↔ '.$s['karsi_eczane'].' '.$kisa($s['karsi_tarih']);
    }

    /**
     * İki nöbetin eczanesini değiştirir. Nöbetlerden biri bu arada değiştiyse (silindi, başka eczaneye verildi, geçmişe
     * kaldı) talep iptal olur ve 409.
     */
    private function uygula(object $t): void
    {
        DB::transaction(function () use ($t) {
            $a = DB::table(Nobetler::TABLO)->where('id', $t->nobet_id)->lockForUpdate()->first();
            $b = DB::table(Nobetler::TABLO)->where('id', $t->karsi_nobet_id)->lockForUpdate()->first();
            $bugun = $this->bugun();
            $gecerli = $a && $b && (int) $a->eczane_id === (int) $t->eczane_id && (int) $b->eczane_id === (int) $t->karsi_eczane_id
                && substr((string) $a->tarih, 0, 10) === substr((string) $t->tarih, 0, 10) && substr((string) $b->tarih, 0, 10) === substr((string) $t->karsi_tarih, 0, 10)
                && substr((string) $a->tarih, 0, 10) >= $bugun && substr((string) $b->tarih, 0, 10) >= $bugun
                && ! isset($this->gunler((int) $t->eczane_id, [(int) $a->id])[substr((string) $b->tarih, 0, 10)])
                && ! isset($this->gunler((int) $t->karsi_eczane_id, [(int) $b->id])[substr((string) $a->tarih, 0, 10)]);
            if (! $gecerli) {
                DB::table(self::TABLO)->where('id', $t->id)->update(['durum' => self::IPTAL, 'updated_at' => Clock::now()]);

                return false;
            }
            $gruplar = DB::table(Eczaneler::TABLO)->whereIn('id', [$t->eczane_id, $t->karsi_eczane_id])->pluck('grup_id', 'id');
            $simdi = Clock::now();
            DB::table(Nobetler::TABLO)->where('id', $a->id)->update(['eczane_id' => (int) $t->karsi_eczane_id, 'grup_id' => $gruplar[$t->karsi_eczane_id] ?? null,
                'kaynak' => Nobetler::ELLE, 'gerekce' => json_encode(['elle' => true, 'takas' => (int) $t->id, 'onceki' => (int) $t->eczane_id]), 'updated_at' => $simdi]);
            DB::table(Nobetler::TABLO)->where('id', $b->id)->update(['eczane_id' => (int) $t->eczane_id, 'grup_id' => $gruplar[$t->eczane_id] ?? null,
                'kaynak' => Nobetler::ELLE, 'gerekce' => json_encode(['elle' => true, 'takas' => (int) $t->id, 'onceki' => (int) $t->karsi_eczane_id]), 'updated_at' => $simdi]);
            DB::table(self::TABLO)->where('id', $t->id)->update(['durum' => self::ONAY, 'updated_at' => $simdi]);

            return true;
        }) ?: throw new ConflictException('nobet_takas_gecersiz', 'Nöbetlerden biri bu arada değişti; talep geçersiz sayıldı. Güncel listeye göre yeniden teklif edin.');
    }

    private function kendiNobeti(object $eczane, int $nobetId): object
    {
        $nobet = DB::table(Nobetler::TABLO)->where('id', $nobetId)->where('eczane_id', $eczane->id)->first();
        if ($nobet === null) {
            throw ValidationException::field('nobet_id', 'Kendi nöbetlerinizden birini seçin.');
        }
        $tarih = substr((string) $nobet->tarih, 0, 10);
        if ($tarih < $this->bugun() || $tarih > $this->sonGun()) {
            throw ValidationException::field('nobet_id', 'Yalnızca gelecek bir nöbet takas edilebilir.');
        }

        return $nobet;
    }

    /** Açık takastaki nöbetler. @return array<int, true> */
    private function acikNobetler(): array
    {
        $out = [];
        foreach (DB::table(self::TABLO)->whereIn('durum', self::ACIK)->get(['nobet_id', 'karsi_nobet_id']) as $t) {
            $out[(int) $t->nobet_id] = true;
            $out[(int) $t->karsi_nobet_id] = true;
        }

        return $out;
    }

    /**
     * Eczanenin gelecekteki nöbet günleri (bazıları hariç).
     *
     * @param  list<int>  $haric
     * @return array<string, true>
     */
    private function gunler(int $eczaneId, array $haric = []): array
    {
        $out = [];
        foreach (DB::table(Nobetler::TABLO)->where('eczane_id', $eczaneId)->where('tarih', '>=', $this->bugun())->whereNotIn('id', $haric ?: [0])->pluck('tarih') as $t) {
            $out[substr((string) $t, 0, 10)] = true;
        }

        return $out;
    }

    private function bugun(): string
    {
        return substr(Clock::now(), 0, 10);
    }

    private function sonGun(): string
    {
        return (new \DateTimeImmutable($this->bugun()))->modify('+'.self::EN_ILERI.' days')->format('Y-m-d');
    }
}
