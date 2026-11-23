<?php

namespace KodmobTools\Nobet\Motor;

use DateTimeImmutable;

/** Bir döngünün bütün girdisi: motor veritabanına bakmaz, ne bilmesi gerekiyorsa burada. */
final class DonguIstegi
{
    /**
     * @param  list<Eczane>  $eczaneler  döngüye katılan (etkin) eczaneler; bölgeler ilk görüldükleri sırayla dolaşılır
     * @param  list<Kural>  $kurallar  seçim kuralları uygulama sırasıyla, yapısallar (K, L, O) herhangi bir yerde
     * @param  Sayaclar  $sayaclar  geçmiş nöbet sayıları (motor kopyasıyla çalışır, verilen nesne değişmez)
     * @param  array<int, array{eslesme?: list<int>, yedek?: int|null}>  $gruplar  grup → eş grupları ve yedek grubu (J)
     * @param  list<string>  $ozelGunler  Y-m-d
     * @param  list<string>  $doluGunler  Y-m-d: nöbetçisi önceden yazılmış, atlanır
     * @param  list<array{0: int, 1: int}>  $ikiliEngeller  aynı gün birlikte nöbetçi olmayacak eczaneler (G)
     * @param  list<array{eczane: int, baslangic: string, bitis: string}>  $tarihEngelleri  eczanenin nöbet tutmayacağı aralıklar (H, iki uç dahil)
     * @param  list<array{0: int, 1: int}>  $grupEngelleri  aynı gün birlikte nöbetçi olmayacak gruplar (I)
     * @param  array<int, int>  $cumartesiGunduzSayisi  bölge → cumartesi gündüz nöbetçi sayısı (K; yazılmayan bölgede 1)
     * @param  int|null  $tohum  kuranın tohumu: aynı girdi ve tohum aynı takvimi verir; boşsa rastgele seçilir
     * @param  array<int, list<string>>  $bilinenNobetler  eczane → döngü dışındaki nöbet günleri (Y-m-d; geçmiş ve önceden
     *                                                     yazılmış): R yakın nöbeti, S son nöbeti buradan da bilir
     * @param  int  $enAzAralikGun  R: aynı eczanenin iki nöbeti arasında en az bu kadar gün (7 → bir hafta dolmadan yok)
     */
    public function __construct(
        public readonly DateTimeImmutable $baslangic,
        public readonly DateTimeImmutable $bitis,
        public readonly array $eczaneler,
        public readonly array $kurallar,
        public readonly Sayaclar $sayaclar = new Sayaclar,
        public readonly array $gruplar = [],
        public readonly array $ozelGunler = [],
        public readonly array $doluGunler = [],
        public readonly array $ikiliEngeller = [],
        public readonly array $tarihEngelleri = [],
        public readonly array $grupEngelleri = [],
        public readonly array $cumartesiGunduzSayisi = [],
        public readonly float $cografiEnAzKm = 2.0,
        public readonly float $cografiUstOran = 0.3,
        public readonly ?int $tohum = null,
        public readonly array $bilinenNobetler = [],
        public readonly int $enAzAralikGun = 7,
    ) {}

    public function var(Kural $kural): bool
    {
        return in_array($kural, $this->kurallar, true);
    }
}
