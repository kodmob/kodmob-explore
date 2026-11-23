<?php

namespace KodmobTools\Nobet\Motor;

/**
 * Döngü oluşturulurken seçilebilen kurallar. Harfler eski sistemle aynıdır: eski döngü kayıtları aktarılınca
 * anlamları korunur ve odalar alıştıkları adlarla seçer.
 *
 * R ve S eski sistemde yoktu (eski readme istekleri): R iki nöbet arasında en az `enAzAralikGun` gün bırakır (A
 * yalnızca aynı takvim haftasına bakıyordu), S eşitlikte kura yerine son nöbeti en eski olanı seçer.
 *
 * Seçim kuralları adayları sırayla daraltır (sıra sonucu değiştirir). Yapısal kurallar (K, L, O) adayları değil
 * takvimin biçimini belirler: hangi günlere, kaç vardiyaya, kaç nöbetçi yazılacağını.
 */
enum Kural: string
{
    case AyniHafta = 'A';
    case AyniAy = 'M';
    case DonemDengesi = 'N';
    case GunSayisi = 'B';
    case MevsimSayisi = 'C';
    case AySayisi = 'D';
    case HaftaTipiSayisi = 'E';
    case IkiliEczaneEngeli = 'G';
    case TarihAraligiEngeli = 'H';
    case GrupEngeli = 'I';
    case EsVeYedekGrup = 'J';
    case CumartesiGunduzGece = 'K';
    case HaftasonuEkNobetci = 'L';
    case OzelGunlerBos = 'O';
    case CografiDenge = 'P';
    case EnAzAralik = 'R';
    case EnUzunSuredir = 'S';

    public function etiket(): string
    {
        return match ($this) {
            self::AyniHafta => 'Aynı hafta ikinci nöbet verilmesin',
            self::AyniAy => 'Aynı ay ikinci nöbet verilmesin',
            self::DonemDengesi => 'Dönem içinde nöbetleri dengele',
            self::GunSayisi => 'O gün en az nöbet tutanlar',
            self::MevsimSayisi => 'O mevsimde en az nöbet tutanlar',
            self::AySayisi => 'O ayda en az nöbet tutanlar',
            self::HaftaTipiSayisi => 'Hafta içi / hafta sonu en az nöbet tutanlar',
            self::IkiliEczaneEngeli => 'İki eczane aynı gün nöbetçi olmasın',
            self::TarihAraligiEngeli => 'Eczane belirli tarihlerde nöbet tutmasın',
            self::GrupEngeli => 'İki grup aynı gün nöbetçi olmasın',
            self::EsVeYedekGrup => 'Günün ilk eczanesinin eş ve yedek grupları',
            self::CumartesiGunduzGece => 'Cumartesi gündüz ve gece ayrı',
            self::HaftasonuEkNobetci => 'Hafta sonu bölge başına bir nöbetçi daha',
            self::OzelGunlerBos => 'Özel günler boş bırakılsın (kurayla belirlenir)',
            self::CografiDenge => 'Aynı gün nöbetçiler birbirinden uzak olsun',
            self::EnAzAralik => 'Aynı eczaneye birkaç gün içinde ikinci nöbet verilmesin',
            self::EnUzunSuredir => 'En uzun süredir nöbet tutmayan seçilsin',
        };
    }

    /** Takvimin biçimini belirleyen kural (adayları daraltmaz). */
    public function yapisal(): bool
    {
        return in_array($this, [self::CumartesiGunduzGece, self::HaftasonuEkNobetci, self::OzelGunlerBos], true);
    }

    /**
     * "A,M,B" ya da ['A', 'M'] → kurallar; sıra korunur, tekrar edilen harf bir kez sayılır.
     *
     * @param  string|list<string>  $harfler
     * @return list<self>
     */
    public static function listeden(string|array $harfler): array
    {
        $liste = is_string($harfler) ? explode(',', $harfler) : $harfler;
        $kurallar = [];
        foreach ($liste as $harf) {
            $harf = strtoupper(trim((string) $harf));
            if ($harf === '') {
                continue;
            }
            $kural = self::tryFrom($harf) ?? throw new MotorHatasi("Bilinmeyen kural: {$harf}");
            if (! in_array($kural, $kurallar, true)) {
                $kurallar[] = $kural;
            }
        }

        return $kurallar;
    }
}
