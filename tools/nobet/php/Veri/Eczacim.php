<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Takvim;

/**
 * Eczacının kendi alanı (Nöbetlerim): hesabına bağlı eczane, gelecek nöbetleri, mazeret ve takas talepleri. Hesap site
 * üyesidir (`nobet_eczaneler.uye_id`; Hesabım › Nöbetlerim) ya da geçiş süresince yönetim arayüzünün panel hesabı
 * (`kullanici_id`; /admin/nobetlerim). Talep `nobet_mazeretler`'e `durum = talep` olarak yazılır; yönetici onaylar ya da
 * reddeder (Mazeretler::karar). Onay listeyi kendiliğinden değiştirmez: yönetici nöbeti Nöbet Kayıtları'nda değiştirir.
 */
final class Eczacim
{
    /** Talep en çok bu kadar gün ilerisi için. */
    private const EN_ILERI = 366;

    /** Panel hesabının (yönetim arayüzü) özeti. */
    public function ozet(User $kullanici): array
    {
        return $this->eczaneOzeti($this->eczaneSatiri($kullanici));
    }

    /** @return array{eczane: array<string, mixed>|null, nobetler: list<array<string, mixed>>, mazeretler: list<array<string, mixed>>, takaslar: list<array<string, mixed>>, gecmis_nobet: int} */
    public function eczaneOzeti(?object $eczane): array
    {
        if ($eczane === null) {
            return ['eczane' => null, 'nobetler' => [], 'mazeretler' => [], 'takaslar' => [], 'gecmis_nobet' => 0];
        }
        $bugun = substr(Clock::now(), 0, 10);
        $sonra = (new \DateTimeImmutable($bugun))->modify('+'.self::EN_ILERI.' days')->format('Y-m-d');
        $ozelAdlari = DB::table(OzelGunler::TABLO)->pluck('ad', 'tarih')->mapWithKeys(fn ($ad, $t) => [substr((string) $t, 0, 10) => $ad])->all();

        $nobetler = DB::table(Nobetler::TABLO)->where('eczane_id', $eczane->id)
            ->where('tarih', '>=', $bugun)->where('tarih', '<=', $sonra)->orderBy('tarih')->get()
            ->map(function ($n) use ($ozelAdlari) {
                $tarih = substr((string) $n->tarih, 0, 10);

                return [
                    'id' => (int) $n->id, 'tarih' => $tarih, 'gun_adi' => Takvim::gun(new \DateTimeImmutable($tarih)), 'vardiya' => $n->vardiya,
                    'ozel_gun' => (bool) $n->ozel_gun, 'ozel_gun_adi' => $ozelAdlari[$tarih] ?? null,
                ];
            })->all();

        return [
            'eczane' => ['id' => (int) $eczane->id, 'ad' => $eczane->ad, 'adres' => $eczane->adres, 'telefon' => $eczane->telefon,
                'bolge' => DB::table(Bolgeler::TABLO)->where('id', $eczane->bolge_id)->value('ad')],
            'nobetler' => $nobetler,
            'mazeretler' => array_map(self::mazeretSatiri(...), DB::table(Mazeretler::TABLO)->where('eczane_id', $eczane->id)
                ->orderByDesc('tarih')->orderByDesc('id')->limit(100)->get()->all()),
            'takaslar' => (new Takaslar)->eczaneninkiler((int) $eczane->id),
            'gecmis_nobet' => DB::table(Nobetler::TABLO)->where('eczane_id', $eczane->id)->where('tarih', '<', $bugun)->count(),
        ];
    }

    /** @return array{id: int, ad: string} */
    public function talep(User $kullanici, array $girdi): array
    {
        return $this->mazeretBildir($this->eczane($kullanici), $girdi, (int) $kullanici->id, null);
    }

    /**
     * Eczanenin mazeret talebi; bildiren panel hesabı (`$kullanici`) ya da üye (`$uye`).
     *
     * @return array{id: int, ad: string}
     */
    public function mazeretBildir(object $eczane, array $girdi, ?int $kullanici, ?int $uye): array
    {
        $tarih = substr(trim((string) ($girdi['tarih'] ?? '')), 0, 10);
        $bugun = substr(Clock::now(), 0, 10);
        $hatalar = [];
        if (! Kayit::tarihMi($tarih)) {
            $hatalar['tarih'] = 'Geçerli bir tarih seçin.';
        } elseif ($tarih < $bugun) {
            $hatalar['tarih'] = 'Geçmiş bir gün için mazeret bildirilemez.';
        } elseif ($tarih > (new \DateTimeImmutable($bugun))->modify('+'.self::EN_ILERI.' days')->format('Y-m-d')) {
            $hatalar['tarih'] = 'En çok bir yıl ilerisi için mazeret bildirilebilir.';
        }
        if (mb_strlen(trim((string) ($girdi['aciklama'] ?? ''))) < 3) {
            $hatalar['aciklama'] = 'Mazeretinizi kısaca yazın.';
        }
        if ($hatalar !== []) {
            throw ValidationException::fields($hatalar);
        }
        $bekleyen = DB::table(Mazeretler::TABLO)->where('eczane_id', $eczane->id)->where('durum', Mazeretler::TALEP)
            ->whereDate('tarih', $tarih)->exists();
        if ($bekleyen) {
            throw new ConflictException('nobet_mazeret_var', 'Bu gün için bekleyen bir talebiniz var.');
        }

        $sonuc = (new Mazeretler)->kaydet(null, ['tarih' => $tarih, 'aciklama' => $girdi['aciklama'], 'tur' => 'mazeret', 'eczane_id' => $eczane->id], $kullanici, Mazeretler::TALEP, $uye);
        Bildirim::talepGeldi($sonuc['id'], (string) $eczane->ad, $tarih, (string) $girdi['aciklama']);

        return ['id' => $sonuc['id'], 'ad' => $eczane->ad.' · '.$sonuc['ad']];
    }

    /** Bekleyen kendi talebini geri alır (karar verilmişse olmaz). */
    public function geriAl(User $kullanici, int $id): string
    {
        return $this->mazeretGeriAl($this->eczane($kullanici), $id);
    }

    public function mazeretGeriAl(object $eczane, int $id): string
    {
        $m = DB::table(Mazeretler::TABLO)->where('id', $id)->where('eczane_id', $eczane->id)->first()
            ?? throw NotFoundException::message('Talep bulunamadı.');
        if ($m->durum !== Mazeretler::TALEP) {
            throw new ConflictException('nobet_mazeret_karar', 'Karar verilmiş talep geri alınamaz.');
        }
        DB::table(Mazeretler::TABLO)->where('id', $id)->delete();

        return $eczane->ad.' · '.substr((string) $m->tarih, 0, 10);
    }

    /** @return array<string, mixed> */
    public static function mazeretSatiri(object $m): array
    {
        return [
            'id' => (int) $m->id, 'tarih' => substr((string) $m->tarih, 0, 10), 'aciklama' => $m->aciklama, 'tur' => $m->tur,
            'durum' => $m->durum, 'karar_notu' => $m->karar_notu ?? null,
            'karar_tarihi' => $m->karar_tarihi ? substr((string) $m->karar_tarihi, 0, 16) : null,
            'eklenme' => substr((string) $m->created_at, 0, 16),
        ];
    }

    private function eczaneSatiri(User $kullanici): ?object
    {
        return DB::table(Eczaneler::TABLO)->where('kullanici_id', $kullanici->id)->first();
    }

    private function eczane(User $kullanici): object
    {
        return self::bagli($this->eczaneSatiri($kullanici));
    }

    /** Hesaba bağlı eczane; bağlı değilse 403. */
    public static function bagli(?object $eczane): object
    {
        return $eczane ?? throw new DomainException('nobet_eczane_yok', 'Hesabınız bir eczaneye bağlı değil; oda yöneticinize başvurun.', 403);
    }
}
