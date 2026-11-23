<?php

namespace KodmobTools\Nobet\Veri;

use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use KodmobTools\Nobet\Motor\Kural;
use KodmobTools\Nobet\Motor\MotorHatasi;
use KodmobTools\Nobet\Motor\Takvim;
use KodmobTools\Nobet\NobetModule;
use PDO;
use RuntimeException;

/**
 * Eski sistemden (eczaciodasi.net, PHP + MySQL) aktarım: bir ilçenin (odanın) bölgeleri, grupları, eczaneleri,
 * özel günleri, döngüleri ve nöbet raporları bu sitenin nobet_* tablolarına. Kimlikler yeniden verilir, bağlar
 * eşlenir. Tek işlemde; `deneme` ise sonunda geri alınır.
 *
 * Eski veride bilinen sorunlar ve karşılıkları:
 * - `raporlar.tarih` TIMESTAMP: MySQL UTC'de saklayıp oturum saat dilimine çevirir; eski sistem kayan tarihleri elle
 *   düzeltiyordu. Kaynak oturumu +03:00'a alınır, tarih gün adıyla (`gun_adi`) denetlenir; tutmazsa ±1 gün denenir.
 * - Eczanelerin sayaç sütunları elle eşitlendiği için bayat olabilir: raporlardan sayılandan büyükse fark sisteme
 *   girilmeden önceki nöbet sayılır ve devir olarak taşınır (küçükse raporlar doğrudur, devir yok).
 * - Özel güne kurayla yazılan eczaneler `ozelgunler.eczane_ids`'te: raporda yoksa özel gün nöbeti olarak eklenir.
 */
final class Aktarim
{
    /** @var array<string, int> */
    private array $ozet = [];

    /** @var list<string> */
    private array $uyarilar = [];

    private ?int $bolgesiz = null;

    public function __construct(private readonly PDO $kaynak) {}

    /** "mysql://kullanici:sifre@sunucu:3306/veritabani" → PDO (oturum Türkiye saati, utf8mb4). */
    public static function baglan(string $adres): PDO
    {
        $p = parse_url($adres);
        if (($p['scheme'] ?? '') !== 'mysql' || empty($p['host']) || empty($p['path']) || trim($p['path'], '/') === '') {
            throw new RuntimeException('Kaynak adresi şöyle olmalı: mysql://kullanici:sifre@sunucu:3306/veritabani');
        }
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['host'], $p['port'] ?? 3306, trim($p['path'], '/')),
            urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $pdo->exec("SET time_zone = '+03:00'");

        return $pdo;
    }

    /** @return list<int> kaynaktaki ilçeler (eczanesi olan) */
    public function ilceler(): array
    {
        return array_map('intval', $this->kaynak->query('SELECT DISTINCT ilce_id FROM eczaneler ORDER BY ilce_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array{ozet: array<string, int>, uyarilar: list<string>, ilce: int}
     */
    public function aktar(?int $ilce, bool $deneme): array
    {
        $ilceler = $this->ilceler();
        if ($ilce === null) {
            if (count($ilceler) !== 1) {
                throw new RuntimeException('Kaynakta birden çok ilçe var ('.implode(', ', $ilceler).'): hangisinin aktarılacağını seçin.');
            }
            $ilce = $ilceler[0];
        } elseif (! in_array($ilce, $ilceler, true)) {
            throw new RuntimeException("Kaynakta {$ilce} ilçesinin eczanesi yok.");
        }
        if (DB::table(Eczaneler::TABLO)->exists() || DB::table(Nobetler::TABLO)->exists() || DB::table(Bolgeler::TABLO)->exists() || DB::table(Adresler::MAHALLELER)->exists()) {
            throw new RuntimeException('Bu sitede nöbet verisi var: aktarım yalnızca boş siteye yapılır.');
        }
        $this->ozet = $this->uyarilar = [];
        $this->bolgesiz = null;

        DB::beginTransaction();
        try {
            $bolgeler = $this->bolgeler($ilce);
            $gruplar = $this->gruplar($ilce, $bolgeler);
            $raporlar = $this->raporlar($ilce);
            $eczaneler = $this->eczaneler($ilce, $bolgeler, $gruplar, $raporlar);
            $ozelGunler = $this->ozelGunler($ilce);
            $donguler = $this->donguler($ilce);
            $this->nobetler($raporlar, $bolgeler, $gruplar, $eczaneler, $donguler);
            $this->ozelGunNobetleri($ozelGunler, $eczaneler);
            $this->odaAyarlari($ilce);
            $this->adresler($ilce);
            $this->mazeretler($ilce);
            $this->uyeler();
            $deneme ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['ozet' => $this->ozet, 'uyarilar' => $this->uyarilar, 'ilce' => $ilce];
    }

    /**
     * Yalnızca üyeler (nöbet verisi önceden aktarılmış siteye): `nobet:aktar … --uyeler`. Aynı e-postalı üye atlanır;
     * tekrar çalıştırmak güvenlidir.
     *
     * @return array{ozet: array<string, int>, uyarilar: list<string>}
     */
    public function uyeleriAktar(bool $deneme): array
    {
        $this->ozet = $this->uyarilar = [];
        DB::beginTransaction();
        try {
            $this->uyeler();
            $deneme ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['ozet' => $this->ozet, 'uyarilar' => $this->uyarilar];
    }

    /** @return list<array<string, mixed>> */
    private function satirlar(string $sql, array $degerler = []): array
    {
        $sorgu = $this->kaynak->prepare($sql);
        $sorgu->execute($degerler);

        return $sorgu->fetchAll(PDO::FETCH_ASSOC);
    }

    private function say(string $ne, int $kac = 1): void
    {
        $this->ozet[$ne] = ($this->ozet[$ne] ?? 0) + $kac;
    }

    /** @return array<int, int> eski → yeni */
    private function bolgeler(int $ilce): array
    {
        $esle = [];
        $simdi = Clock::now();
        foreach ($this->satirlar('SELECT id, bolge_title FROM bolgeler WHERE ilce_id = ? ORDER BY id', [$ilce]) as $i => $b) {
            $ad = trim((string) $b['bolge_title']) ?: "Bölge {$b['id']}";
            $esle[(int) $b['id']] = (int) DB::table(Bolgeler::TABLO)->insertGetId(['ad' => mb_substr($ad, 0, 80), 'sira' => $i + 1, 'created_at' => $simdi, 'updated_at' => $simdi]);
            $this->say('bolge');
        }

        return $esle;
    }

    /** @param array<int, int> $bolgeler  @return array<int, int> eski → yeni */
    private function gruplar(int $ilce, array $bolgeler): array
    {
        $satirlar = $this->satirlar('SELECT id, grup_title, grup_eslesme_ids, grup_yedek_id FROM gruplar WHERE ilce_id = ? ORDER BY id', [$ilce]);
        $esle = [];
        $simdi = Clock::now();
        $adlar = [];
        foreach ($satirlar as $g) {
            $ad = trim((string) $g['grup_title']) ?: "Grup {$g['id']}";
            // Aynı adlı iki grup olabilir; panel benzersiz ad ister.
            $ad = isset($adlar[$ad]) ? "{$ad} ({$g['id']})" : $ad;
            $adlar[$ad] = true;
            $esle[(int) $g['id']] = (int) DB::table(Gruplar::TABLO)->insertGetId(['ad' => mb_substr($ad, 0, 80), 'eslesme' => '[]', 'created_at' => $simdi, 'updated_at' => $simdi]);
            $this->say('grup');
        }
        foreach ($satirlar as $g) {
            $eslesme = array_values(array_unique(array_filter(array_map(
                fn ($id) => $esle[(int) $id] ?? null,
                array_filter(explode(',', (string) $g['grup_eslesme_ids']), fn (string $v) => trim($v) !== ''),
            ))));
            $yedek = $g['grup_yedek_id'] ? ($esle[(int) $g['grup_yedek_id']] ?? null) : null;
            DB::table(Gruplar::TABLO)->where('id', $esle[(int) $g['id']])->update(['eslesme' => json_encode($eslesme), 'yedek_id' => $yedek]);
        }

        return $esle;
    }

    /**
     * Raporlar, tarihleri düzeltilmiş: [eski id, eczane, tarih Y-m-d, vardiya, özel gün, bölge, grup, döngü].
     *
     * @return list<array{eczane: int, tarih: string, vardiya: string, ozel: bool, bolge: int|null, grup: int|null, dongu: int|null}>
     */
    private function raporlar(int $ilce): array
    {
        $liste = [];
        foreach ($this->satirlar('SELECT * FROM raporlar WHERE ilce_id = ? ORDER BY tarih, id', [$ilce]) as $r) {
            $tarih = $this->tarih((string) $r['tarih'], (string) ($r['gun_adi'] ?? ''), (int) $r['id']);
            if ($tarih === null) {
                continue;
            }
            $vardiya = in_array($r['nobet_tipi'] ?? '', ['tumgun', 'gunduz', 'gece'], true) ? $r['nobet_tipi'] : 'tumgun';
            $liste[] = [
                'eczane' => (int) $r['eczane_id'], 'tarih' => $tarih, 'vardiya' => $vardiya, 'ozel' => (bool) $r['ozel_gun'],
                'bolge' => $r['bolge_id'] !== null ? (int) $r['bolge_id'] : null,
                'grup' => $r['grup_id'] ? (int) $r['grup_id'] : null,
                'dongu' => $r['dongu_id'] ? (int) $r['dongu_id'] : null,
            ];
        }

        return $liste;
    }

    /** Raporun tarihi: gün adı tutuyorsa olduğu gibi, tutmuyorsa ±1 günden tutanı (eski kayma hatası). */
    private function tarih(string $deger, string $gunAdi, int $id): ?string
    {
        $gun = substr($deger, 0, 10);
        if (! Kayit::tarihMi($gun)) {
            $this->uyarilar[] = "Rapor {$id}: tarih okunamadı ({$deger}), atlandı.";
            $this->say('atlanan_rapor');

            return null;
        }
        if ($gunAdi === '' || Takvim::gun(new DateTimeImmutable($gun)) === $gunAdi) {
            return $gun;
        }
        foreach (['+1 day', '-1 day'] as $kayma) {
            $aday = (new DateTimeImmutable($gun))->modify($kayma);
            if (Takvim::gun($aday) === $gunAdi) {
                $this->say('duzeltilen_tarih');

                return $aday->format('Y-m-d');
            }
        }
        $this->uyarilar[] = "Rapor {$id}: {$gun} tarihi gün adıyla ({$gunAdi}) tutmuyor; tarih olduğu gibi alındı.";

        return $gun;
    }

    /**
     * @param  array<int, int>  $bolgeler
     * @param  array<int, int>  $gruplar
     * @param  list<array<string, mixed>>  $raporlar
     * @return array<int, array{id: int, bolge: int, grup: int|null}> eski → yeni
     */
    private function eczaneler(int $ilce, array $bolgeler, array $gruplar, array $raporlar): array
    {
        // Raporlardan sayılan (özel gün dışı) nöbetler: eski sayaçla farkı devir.
        $sayilan = [];
        foreach ($raporlar as $r) {
            if (! $r['ozel']) {
                foreach (Takvim::anahtarlar(new DateTimeImmutable($r['tarih'])) as $anahtar) {
                    $sayilan[$r['eczane']][$anahtar] = ($sayilan[$r['eczane']][$anahtar] ?? 0) + 1;
                }
            }
        }

        $esle = [];
        $simdi = Clock::now();
        foreach ($this->satirlar('SELECT * FROM eczaneler WHERE ilce_id = ? ORDER BY id', [$ilce]) as $e) {
            $eski = (int) $e['id'];
            $bolge = $bolgeler[(int) $e['bolge_id']] ?? $this->bolgesizBolge();
            $grup = (int) $e['grup_id'] ? ($gruplar[(int) $e['grup_id']] ?? null) : null;
            [$enlem, $boylam] = $this->konum((string) ($e['latlong'] ?? ''));
            $devir = [];
            foreach (Eczaneler::devirAnahtarlari() as $anahtar) {
                $fark = (int) ($e[$anahtar.'Sayisi'] ?? 0) - ($sayilan[$eski][$anahtar] ?? 0);
                if ($fark > 0) {
                    $devir[$anahtar] = $fark;
                }
            }
            if ($devir !== []) {
                $this->say('devirli_eczane');
            }
            $adres = implode(' ', array_filter(array_map(fn ($a) => trim((string) ($e[$a] ?? '')), ['mahalle', 'cadde', 'binano'])));
            $id = (int) DB::table(Eczaneler::TABLO)->insertGetId([
                'ad' => mb_substr(trim((string) $e['adi']), 0, 120),
                'telefon' => self::telefon((string) ($e['tel'] ?? '')),
                'adres' => $adres !== '' ? mb_substr($adres, 0, 300) : null,
                'sehir' => $this->metin($e['sehir'] ?? null, 80),
                'ilce' => $this->metin($e['ilce'] ?? null, 80),
                'mahalle' => $this->metin($e['mahalle'] ?? null, 150),
                'cadde' => $this->metin($e['cadde'] ?? null, 150),
                'binano' => $this->metin($e['binano'] ?? null, 30),
                'renk' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($e['renk'] ?? '')) ? strtoupper((string) $e['renk']) : null,
                'enlem' => $enlem, 'boylam' => $boylam,
                'bolge_id' => $bolge,
                'grup_id' => $grup,
                'aktif' => (string) $e['durum'] === '1',
                'devir' => $devir === [] ? null : json_encode($devir),
                'created_at' => $simdi, 'updated_at' => $simdi,
            ]);
            $esle[$eski] = ['id' => $id, 'bolge' => $bolge, 'grup' => $grup];
            $this->say((string) $e['durum'] === '1' ? 'eczane' : 'pasif_eczane');
        }

        return $esle;
    }

    private function metin(mixed $deger, int $en): ?string
    {
        $deger = trim((string) $deger);

        return $deger === '' ? null : mb_substr($deger, 0, $en);
    }

    /** Odanın il ve ilçe kodu (arayüzün GLOBAL_SEHIR_KEY / GLOBAL_ILCE_ID'si): kaynağın ilce tablosundan. */
    private function odaAyarlari(int $ilce): void
    {
        try {
            $sehir = $this->satirlar('SELECT ilce_sehirkey FROM ilce WHERE ilce_key = ?', [$ilce])[0]['ilce_sehirkey'] ?? null;
        } catch (\PDOException) {
            $sehir = null; // kaynakta ilce tablosu yok
        }
        SettingsService::instance()->set(NobetModule::ILCE_KEY, $ilce);
        if ($sehir !== null) {
            SettingsService::instance()->set(NobetModule::SEHIR_KEY, (int) $sehir);
        }
    }

    /**
     * Odanın ilçesinin mahalle ve caddeleri (eczane adres formu). Eski veride caddelerin çoğunun ilçe kodu boş:
     * caddeler mahalle anahtarıyla bulunur. Kaynakta tablolar yoksa atlanır.
     */
    private function adresler(int $ilce): void
    {
        try {
            $mahalleler = $this->satirlar('SELECT mahalle_title, mahalle_key FROM mahalle WHERE mahalle_ilcekey = ? ORDER BY mahalle_title', [$ilce]);
            $caddeler = $this->satirlar('SELECT c.sokak_cadde_title, c.sokak_cadde_mahallekey FROM sokak_cadde c JOIN mahalle m ON m.mahalle_key = c.sokak_cadde_mahallekey WHERE m.mahalle_ilcekey = ?', [$ilce]);
        } catch (\PDOException) {
            return;
        }
        $simdi = Clock::now();
        $anahtarlar = [];
        foreach ($mahalleler as $m) {
            $anahtar = (int) $m['mahalle_key'];
            if (isset($anahtarlar[$anahtar])) {
                continue;
            }
            $anahtarlar[$anahtar] = true;
            DB::table(Adresler::MAHALLELER)->insert(['ad' => mb_substr(trim((string) $m['mahalle_title']), 0, 150), 'anahtar' => $anahtar, 'ilce_key' => $ilce, 'created_at' => $simdi]);
        }
        $satirlar = array_map(fn ($c) => ['ad' => mb_substr(trim((string) $c['sokak_cadde_title']), 0, 150), 'mahalle_anahtar' => (int) $c['sokak_cadde_mahallekey'], 'created_at' => $simdi], $caddeler);
        foreach (array_chunk($satirlar, 500) as $parca) {
            DB::table(Adresler::CADDELER)->insert($parca);
        }
        $this->say('mahalle', count($anahtarlar));
        $this->say('cadde', count($satirlar));
    }

    /** Mazeretler (bir güne bağlı not). Kaynakta tablo yoksa atlanır. */
    private function mazeretler(int $ilce): void
    {
        try {
            $satirlar = $this->satirlar('SELECT * FROM mazeretler WHERE ilce_id = ? ORDER BY gunu, id', [$ilce]);
        } catch (\PDOException) {
            return;
        }
        foreach ($satirlar as $m) {
            $tarih = substr((string) $m['gunu'], 0, 10);
            $aciklama = trim((string) $m['aciklama']);
            if (! Kayit::tarihMi($tarih) || $aciklama === '') {
                continue;
            }
            $tur = in_array($m['turu'] ?? '', Mazeretler::TURLER, true) ? $m['turu'] : 'mazeret';
            $eklenme = substr((string) ($m['tarih'] ?? ''), 0, 19) ?: Clock::now();
            DB::table(Mazeretler::TABLO)->insert(['tarih' => $tarih, 'aciklama' => mb_substr($aciklama, 0, 500), 'tur' => $tur, 'durum' => 'kayit', 'created_at' => $eklenme, 'updated_at' => $eklenme]);
            $this->say('mazeret');
        }
    }

    /**
     * Üyeler → Kodmob kullanıcıları (Veri\Uyeler): eski `rol = 1` Oda yöneticisi, diğerleri Eczacı. Şifre özeti
     * (bcrypt) olduğu gibi taşınır: herkes eski şifresiyle, e-postasıyla ya da türetilen kullanıcı adıyla girer.
     * E-postası boş, geçersiz ya da bu sitede kayıtlı olan üye atlanır. Kaynakta tablo yoksa atlanır.
     */
    private function uyeler(): void
    {
        try {
            $satirlar = $this->satirlar('SELECT * FROM uyeler ORDER BY id');
        } catch (\PDOException) {
            return;
        }
        Uyeler::roller();
        foreach ($satirlar as $u) {
            $eposta = mb_strtolower(trim((string) ($u['eposta'] ?? '')));
            $ozet = (string) ($u['sifre'] ?? '');
            if (! filter_var($eposta, FILTER_VALIDATE_EMAIL) || DB::table('users')->where('email', $eposta)->exists()) {
                $this->uyarilar[] = sprintf('Üye #%d aktarılmadı: e-postası boş, geçersiz ya da bu sitede zaten kayıtlı.', $u['id']);

                continue;
            }
            if (! preg_match('/^\$2[aby]\$\d{2}\$/', $ozet)) {
                $this->uyarilar[] = sprintf('Üye #%d aktarılmadı: şifresi tanınan biçimde değil (yeni şifreyle eklenmeli).', $u['id']);

                continue;
            }
            $ad = trim((string) ($u['adi'] ?? '')) ?: strstr($eposta, '@', true);
            $tarih = substr((string) ($u['tarih'] ?? ''), 0, 19) ?: Clock::now();
            DB::table('users')->insert([
                'username' => Uyeler::kullaniciAdi($eposta, $ad),
                'email' => $eposta,
                'display_name' => mb_substr(mb_strlen($ad) < 2 ? $ad.'.' : $ad, 0, 120),
                'phone' => self::telefon((string) ($u['telefon'] ?? '')) ?? '',
                // Özet olduğu gibi (modelin 'hashed' dönüşümü atlanır; Laravel bcrypt özetini doğrular).
                'password' => $ozet,
                'role' => Uyeler::rol($u['rol'] ?? 0),
                'status' => (string) ($u['aktivasyon'] ?? '1') === '1' ? 'active' : 'passive',
                'password_changed_at' => $tarih,
                'created_at' => $tarih,
                'updated_at' => Clock::now(),
            ]);
            $this->say('uye');
        }
    }

    /** Bölgesi bulunamayan eczaneler için tek "Bölgesiz" bölge (ilk gerektiğinde açılır). */
    private function bolgesizBolge(): int
    {
        if ($this->bolgesiz === null) {
            $simdi = Clock::now();
            $this->bolgesiz = (int) DB::table(Bolgeler::TABLO)->insertGetId(['ad' => 'Bölgesiz', 'sira' => 99, 'created_at' => $simdi, 'updated_at' => $simdi]);
            $this->uyarilar[] = 'Bölgesi bulunamayan eczaneler "Bölgesiz" bölgesine kondu; panelden düzeltin.';
        }

        return $this->bolgesiz;
    }

    /** "37.07, 36.22" ya da "37.07 36.22" → [enlem, boylam]; geçersizse [null, null]. @return array{0: string|null, 1: string|null} */
    private function konum(string $latlong): array
    {
        $parca = preg_split('/[\s,;]+/', trim($latlong)) ?: [];
        if (count($parca) < 2 || ! is_numeric($parca[0]) || ! is_numeric($parca[1]) || abs((float) $parca[0]) > 90 || abs((float) $parca[1]) > 180) {
            return [null, null];
        }

        return [(string) round((float) $parca[0], 7), (string) round((float) $parca[1], 7)];
    }

    /**
     * Türkiye numarası "0312 555 01 01" biçimine: 10 hanede başa 0 (eski veride sık), +90 / 90 öneki atılır; 7 haneden
     * kısa olan (ör. "00", "0328") numara sayılmaz. Biçimi tanınmayan ama yeterince uzun numara olduğu gibi kalır.
     */
    public static function telefon(string $tel): ?string
    {
        $rakam = preg_replace('/\D/', '', $tel) ?? '';
        if (strlen($rakam) < 7) {
            return null;
        }
        if (strlen($rakam) === 12 && str_starts_with($rakam, '90')) {
            $rakam = substr($rakam, 2);
        }
        if (strlen($rakam) === 10 && ! str_starts_with($rakam, '0')) {
            $rakam = '0'.$rakam;
        }
        if (strlen($rakam) === 11 && str_starts_with($rakam, '0')) {
            return sprintf('%s %s %s %s', substr($rakam, 0, 4), substr($rakam, 4, 3), substr($rakam, 7, 2), substr($rakam, 9, 2));
        }

        return mb_substr(trim(preg_replace('/[^0-9 +()\-]/', '', $tel) ?? ''), 0, 30);
    }

    /** Özel günleri yazar (adı boşsa "Özel gün"). @return array<string, list<int>> Y-m-d → o güne kurayla yazılan eski eczane kimlikleri */
    private function ozelGunler(int $ilce): array
    {
        $gunler = [];
        $simdi = Clock::now();
        foreach ($this->satirlar('SELECT * FROM ozelgunler WHERE ilce_id = ? ORDER BY tarih, id', [$ilce]) as $g) {
            $tarih = substr((string) $g['tarih'], 0, 10);
            if (! Kayit::tarihMi($tarih) || isset($gunler[$tarih])) {
                $this->say('atlanan_ozel_gun');

                continue;
            }
            $ad = trim((string) $g['adi']);
            DB::table(OzelGunler::TABLO)->insert(['tarih' => $tarih, 'ad' => mb_substr($ad !== '' ? $ad : 'Özel gün', 0, 120), 'created_at' => $simdi, 'updated_at' => $simdi]);
            $gunler[$tarih] = array_values(array_filter(array_map('intval', explode(',', (string) $g['eczane_ids']))));
            $this->say('ozel_gun');
        }

        return $gunler;
    }

    /** @return array<int, int> eski döngü → yeni */
    private function donguler(int $ilce): array
    {
        $esle = [];
        foreach ($this->satirlar('SELECT * FROM donguler WHERE ilce_id = ? ORDER BY baslangic, id', [$ilce]) as $d) {
            $bas = substr((string) $d['baslangic'], 0, 10);
            $bit = substr((string) $d['bitis'], 0, 10);
            if (! Kayit::tarihMi($bas) || ! Kayit::tarihMi($bit)) {
                continue;
            }
            try {
                $kurallar = implode(',', array_map(fn (Kural $k) => $k->value, Kural::listeden((string) $d['method'])));
            } catch (MotorHatasi) {
                $kurallar = '';
                $this->uyarilar[] = "Döngü {$d['adi']}: kurallar okunamadı ({$d['method']}).";
            }
            $esle[(int) $d['id']] = (int) DB::table(Donguler::TABLO)->insertGetId([
                'ad' => mb_substr(trim((string) $d['adi']) ?: "Döngü {$bas}", 0, 120),
                'baslangic' => $bas, 'bitis' => $bit, 'kurallar' => $kurallar,
                'ayarlar' => json_encode(['aktarim' => true]),
                'tohum' => 'aktarim', 'atama_sayisi' => 0,
                'created_at' => substr((string) ($d['tarih'] ?? ''), 0, 19) ?: Clock::now(),
            ]);
            $this->say('dongu');
        }

        return $esle;
    }

    /**
     * @param  list<array<string, mixed>>  $raporlar
     * @param  array<int, int>  $bolgeler
     * @param  array<int, int>  $gruplar
     * @param  array<int, array{id: int, bolge: int, grup: int|null}>  $eczaneler
     * @param  array<int, int>  $donguler
     */
    private function nobetler(array $raporlar, array $bolgeler, array $gruplar, array $eczaneler, array $donguler): void
    {
        $simdi = Clock::now();
        $satirlar = [];
        $dongudeki = [];
        foreach ($raporlar as $r) {
            $eczane = $eczaneler[$r['eczane']] ?? null;
            if ($eczane === null) {
                $this->say('eczanesi_olmayan_rapor');

                continue;
            }
            $dongu = $r['dongu'] !== null ? ($donguler[$r['dongu']] ?? null) : null;
            $satirlar[] = [
                'tarih' => $r['tarih'], 'vardiya' => $r['vardiya'],
                // Rapordaki bölge yoksa eczanenin bugünkü bölgesi.
                'bolge_id' => $r['bolge'] !== null ? ($bolgeler[$r['bolge']] ?? $eczane['bolge']) : $eczane['bolge'],
                'eczane_id' => $eczane['id'],
                'grup_id' => $r['grup'] !== null ? ($gruplar[$r['grup']] ?? $eczane['grup']) : $eczane['grup'],
                'ozel_gun' => $r['ozel'], 'dongu_id' => $dongu, 'kaynak' => Nobetler::AKTARIM, 'gerekce' => null,
                'created_at' => $simdi, 'updated_at' => $simdi,
            ];
            if ($dongu !== null) {
                $dongudeki[$dongu] = ($dongudeki[$dongu] ?? 0) + 1;
            }
        }
        foreach (array_chunk($satirlar, 200) as $parca) {
            DB::table(Nobetler::TABLO)->insert($parca);
        }
        foreach ($dongudeki as $id => $sayi) {
            DB::table(Donguler::TABLO)->where('id', $id)->update(['atama_sayisi' => $sayi]);
        }
        $this->say('nobet', count($satirlar));
    }

    /**
     * Özel güne kurayla yazılan eczaneler (`eczane_ids`): o gün raporda yoksa özel gün nöbeti olarak eklenir.
     *
     * @param  array<string, list<int>>  $ozelGunler
     * @param  array<int, array{id: int, bolge: int, grup: int|null}>  $eczaneler
     */
    private function ozelGunNobetleri(array $ozelGunler, array $eczaneler): void
    {
        $simdi = Clock::now();
        foreach ($ozelGunler as $tarih => $idler) {
            foreach ($idler as $eski) {
                $eczane = $eczaneler[$eski] ?? null;
                if ($eczane === null || DB::table(Nobetler::TABLO)->where('tarih', $tarih)->where('eczane_id', $eczane['id'])->exists()) {
                    continue;
                }
                DB::table(Nobetler::TABLO)->insert([
                    'tarih' => $tarih, 'vardiya' => 'tumgun', 'bolge_id' => $eczane['bolge'], 'eczane_id' => $eczane['id'],
                    'grup_id' => $eczane['grup'], 'ozel_gun' => true, 'dongu_id' => null, 'kaynak' => Nobetler::AKTARIM,
                    'gerekce' => null, 'created_at' => $simdi, 'updated_at' => $simdi,
                ]);
                $this->say('ozel_gun_nobeti');
            }
        }
    }
}
