<?php

namespace KodmobTools\Nobet;

use App\Core\Database\BackupPart;
use App\Core\Module;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kodmob\Members\AccountPages;
use Kodmob\Members\Organizations;
use KodmobTools\Nobet\Veri\Adresler;
use KodmobTools\Nobet\Veri\Aktarim;
use KodmobTools\Nobet\Veri\Bolgeler;
use KodmobTools\Nobet\Veri\Donguler;
use KodmobTools\Nobet\Veri\Eczaneler;
use KodmobTools\Nobet\Veri\Gruplar;
use KodmobTools\Nobet\Veri\Mazeretler;
use KodmobTools\Nobet\Veri\Nobetler;
use KodmobTools\Nobet\Veri\Oda;
use KodmobTools\Nobet\Veri\OzelGunler;
use KodmobTools\Nobet\Veri\Takaslar;
use KodmobTools\Nobet\Veri\UyeGecisi;

/**
 * Nöbet: eczacı odasının bölgeleri, grupları, eczaneleri, özel günleri; kurallarla nöbet döngüsü (Motor\NobetMotoru)
 * ve takvim. Her oda (alt site) kendi veritabanında.
 */
final class NobetModule extends Module
{
    public const GORME = 'km_view_nobet';

    public const YONETME = 'km_manage_nobet';

    public const DEVIR_SAATI = 'nobet.devir_saati';

    public const ILCE_KEY = 'nobet.ilce_key';

    public const SEHIR_KEY = 'nobet.sehir_key';

    public const MERKEZ = 'nobet.merkez';

    /** Odanın kurumu (Site üyeliği › Kurumlar): yöneticileri Hesabım › Oda yönetimi'ni görür. */
    public const KURUM = 'nobet.kurum';

    public const TAKAS_ONAYI = 'nobet.takas_onayi';

    public function settings(): array
    {
        return [
            new SettingDefinition(self::DEVIR_SAATI, 'string', '08:30', 'Nöbet devir saati', 'Nöbet',
                'Sitedeki Nöbetçi Eczaneler sayfası bu saatten önce bir önceki günün nöbetçisini gösterir (gece 02:00\'de dünün nöbetçisi açıktır). SS:DD biçiminde, ör. 08:30.', scope: 'project'),
            new SettingDefinition(self::SEHIR_KEY, 'int', 0, 'Odanın ili (plaka kodu)', 'Nöbet',
                'Yönetim arayüzünde il seçiminin başlangıç değeri, ör. Osmaniye 80. Eski sistemden aktarımda kendiliğinden yazılır.', 0, 81, scope: 'project'),
            new SettingDefinition(self::ILCE_KEY, 'int', 0, 'Odanın ilçe kodu', 'Nöbet',
                'Eski sistemin ilçe kodu (ör. Kadirli 1423); arayüzdeki ilçe seçimleri ve kayıtların ilce_id\'si. Aktarımda kendiliğinden yazılır.', 0, 99999, scope: 'project'),
            new SettingDefinition(self::MERKEZ, 'string', '', 'Haritanın merkezi', 'Nöbet',
                'Harita ve konum seçiminin başlangıç noktası: enlem, boylam (ör. 37.1234, 36.5678).', scope: 'project'),
            new SettingDefinition(self::KURUM, 'int', 0, 'Odanın kurumu', 'Nöbet',
                'Eczacıların üye olduğu kurumun numarası (Gelenler › Kurumlar). Kurumun yöneticileri Hesabım\'da Oda yönetimi sayfasını görür: mazeret ve takas kararları, eczane ↔ hesap bağı. php artisan nobet:uyelere kendiliğinden yazar.', 0, null, scope: 'project'),
            new SettingDefinition(self::TAKAS_ONAYI, 'bool', true, 'Nöbet takası oda onayından geçsin', 'Nöbet',
                'Açıksa iki eczanenin anlaştığı takas oda yönetimi onaylayınca uygulanır. Kapalıysa karşı eczane kabul edince nöbetler hemen değişir.', scope: 'project'),
        ];
    }

    /**
     * Genel bakış › "Sıradaki adımlar": odanın nöbet sistemini kurarken sırayla yapılacaklar (çatının site adımlarından
     * önce). Hepsi gerçek veriden işaretlenir; döngü adımı takvim iki hafta ileriye dolu değilse yeniden açılır.
     */
    public function steps(): array
    {
        if (! Schema::hasTable(Nobetler::TABLO)) {
            return [];
        }
        $ayar = SettingsService::instance();
        $yil = now()->year;
        $ikiHafta = now()->addDays(14)->toDateString();
        $nobet = static fn (string $sekme) => ['route' => 'nobet', 'params' => ['tab' => $sekme], 'can' => self::YONETME];

        return [
            ['key' => 'nobet-oda', 'order' => 1, 'label' => 'Odanın il, ilçe ve harita merkezi', 'hint' => 'Yönetim arayüzündeki il / ilçe seçimleri ve haritanın açılış noktası. Eski sistemden aktarımda kendiliğinden dolar.',
                'done' => $ayar->int(self::SEHIR_KEY) > 0 && $ayar->int(self::ILCE_KEY) > 0 && trim($ayar->string(self::MERKEZ)) !== '',
                'route' => 'projectSettings', 'anchor' => self::SEHIR_KEY, 'can' => Capability::MANAGE_SETTINGS],
            ['key' => 'nobet-bolgeler', 'order' => 2, 'label' => 'Nöbet bölgelerini ekleyin', 'hint' => 'Her bölgeye her gün bir nöbetçi düşer. Gerekirse eşleşen ya da birbirini dışlayan grupları da tanımlayın.',
                'done' => DB::table(Bolgeler::TABLO)->exists()] + $nobet('bolgeler'),
            ['key' => 'nobet-eczaneler', 'order' => 3, 'label' => 'Eczaneleri ekleyin', 'hint' => 'Bölgesi, telefonu, adresi ve haritadaki konumuyla; Excel\'den toplu da eklenebilir.',
                'done' => DB::table(Eczaneler::TABLO)->where('aktif', true)->exists()] + $nobet('eczaneler'),
            ['key' => 'nobet-ozel-gunler', 'order' => 4, 'label' => $yil.' özel günlerini girin', 'hint' => 'Bayram ve resmî tatiller: döngü bu günleri ayrı sayar (O kuralı).',
                'done' => DB::table(OzelGunler::TABLO)->whereYear('tarih', $yil)->exists()] + $nobet('ozelGunler'),
            ['key' => 'nobet-dongu', 'order' => 5, 'label' => 'Nöbet döngüsünü oluşturun', 'hint' => 'Kuralları seçip önizleyin, sonra kaydedin. Takvim en az iki hafta ileriye dolu olmalı.',
                'done' => DB::table(Nobetler::TABLO)->where('tarih', '>=', $ikiHafta)->exists()] + $nobet('dongu'),
        ];
    }

    /**
     * Genel bakış kartı "Bu panelde neler yapılır": odanın işleri kısa açıklamalı bağlantılarla (yetkiye göre süzülür).
     * Yalnızca bu panelde yapılan işler: oda yöneticisi ve eczacıların ekranı (/admin/) burada anlatılmaz.
     */
    public function widgets(): array
    {
        return [[
            'key' => 'nobet-rehber', 'label' => 'Bu panelde neler yapılır', 'icon' => 'compass', 'type' => 'list', 'size' => 'lg', 'order' => 1,
            'can' => self::GORME, 'description' => 'Odanın nöbet işleri ve panelin ilgili ekranları, kısa açıklamalarıyla.',
            'data' => static function (array $ctx) {
                $can = $ctx['can'];
                $nobet = static fn (?string $sekme = null) => ['route' => 'nobet', 'params' => $sekme ? ['tab' => $sekme] : []];
                $satirlar = [
                    ['Nöbet takvimi', 'Günlerin nöbetçileri; tek nöbeti aynı bölgeden başka eczaneye verin.', $nobet(), self::GORME],
                    ['Nöbet döngüsü', 'Tarih aralığı ve kuralları seçin, önizleyin, kaydedin: takvim dolar.', $nobet('dongu'), self::YONETME],
                    ['Eczaneler', 'Bölge, grup, telefon, adres ve konum; pasife alınan döngüye girmez.', $nobet('eczaneler'), self::YONETME],
                    ['Bölgeler ve gruplar', 'Her bölgeye her gün bir nöbetçi; gruplar birlikte ya da ayrı tutmayı belirler.', $nobet('bolgeler'), self::YONETME],
                    ['Özel günler', 'Bayram ve resmî tatiller; döngü bu günleri ayrı ve adil dağıtır.', $nobet('ozelGunler'), self::YONETME],
                    ['Kullanıcılar', 'Oda yöneticisi ve eczacı hesapları, rolleri, oturumları.', ['route' => 'users'], Capability::MANAGE_USERS],
                    // Talepler resmî modül (Kodmob 3.2): yetki adıyla; modül kapalıysa yetki yoktur, satır görünmez.
                    ['Talepler', 'Siteden gelen iletişim mesajları ve yeni oda başvuruları.', ['route' => 'briefs'], 'km_view_briefs'],
                    ['Nöbet ayarları', 'Devir saati, odanın il ve ilçesi, haritanın merkezi.', ['route' => 'projectSettings'], Capability::MANAGE_SETTINGS],
                    ['Yedekleme', 'Nöbet verisi dahil günlük yedek; geri yükleme.', ['route' => 'settings', 'params' => ['tab' => 'backup']], Capability::MANAGE_BACKUPS],
                ];
                $items = [];
                foreach ($satirlar as [$baslik, $aciklama, $hedef, $yetki]) {
                    if ($can($yetki)) {
                        $items[] = ['title' => $baslik, 'meta' => $aciklama] + $hedef;
                    }
                }

                return ['items' => $items];
            },
        ]];
    }

    public function capabilities(): array
    {
        return [
            self::GORME => ['label' => 'Nöbet takvimini ve kayıtları görme', 'group' => 'Nöbet'],
            self::YONETME => ['label' => 'Nöbet: eczane, bölge, grup, özel gün ve döngü yönetme', 'group' => 'Nöbet'],
        ];
    }

    /** Tablolar henüz kurulmadıysa (paket yeni eklendi, kurulum adresi açılmadı) yedek bozulmasın: yalnızca var olanlar. */
    public function backup(): array
    {
        $tablolar = array_values(array_filter(
            [Bolgeler::TABLO, Gruplar::TABLO, Eczaneler::TABLO, OzelGunler::TABLO, Donguler::TABLO, Nobetler::TABLO, Mazeretler::TABLO, Takaslar::TABLO, Adresler::MAHALLELER, Adresler::CADDELER],
            static fn (string $tablo) => Schema::hasTable($tablo),
        ));

        return $tablolar === [] ? [] : [new BackupPart('nobet', 'Nöbet: bölgeler, gruplar, eczaneler, özel günler, döngüler, nöbetler', $tablolar)];
    }

    /**
     * Komut: eski sistemden aktarım (yalnızca komut satırında; panelde düğmesi yok, kaynağın veritabanı şifresi gerekir).
     *
     *   php artisan nobet:aktar "mysql://kullanici:sifre@127.0.0.1:3306/eczane" [--ilce=1423] [--deneme]
     *   php artisan nobet:aktar "mysql://…/eczane" --uyeler     (yalnızca üyeler: Oda yöneticisi / Eczacı, şifreleriyle)
     *   KODMOB_SITE=kadirli php artisan nobet:aktar …     (alt siteye)
     *   php artisan nobet:uyelere [--oda="Kadirli Eczacı Odası"] [--pasif] [--deneme]   (eczacılar site üyesine; Veri\UyeGecisi)
     *
     * Üye paneli (Hesabım; Site üyeliği açıksa): Nöbetlerim eczaneye bağlı üyeye ya da odanın kurumundaki üyeye (bağı
     * bekleyen), Oda yönetimi yalnızca odanın kurumu seçiliyken ve kurumun yöneticisine.
     */
    public function boot(): void
    {
        if (Modules::enabled('members') && class_exists(AccountPages::class)) {
            AccountPages::gate('nobet-nobetlerim', static fn (array $uye) => Schema::hasTable(Eczaneler::TABLO)
                && (Oda::uyeninEczanesi((int) $uye['id']) !== null || (Oda::kurum() > 0 && Organizations::role(Oda::kurum(), (int) $uye['id']) !== null)));
            AccountPages::gate('nobet-oda', static fn (array $uye, ?array $kurum) => Oda::kurum() > 0 && (int) ($kurum['id'] ?? 0) === Oda::kurum());
            Organizations::onDelete(static function (int $kurum) {
                if (SettingsService::instance()->int(self::KURUM) === $kurum) {
                    SettingsService::instance()->set(self::KURUM, 0);
                }
            });
        }
        if (! app()->runningInConsole()) {
            return;
        }
        Artisan::command('nobet:uyelere {--oda= : odanın kurum adı (kurum yoksa açılır; boşsa sitenin adı)} {--pasif : taşınan eczacıların panel hesaplarını pasife al} {--deneme : yazmadan dene, sonunda geri al}', function () {
            /** @var Command $this */
            $sonuc = UyeGecisi::calistir($this->option('oda') !== null ? (string) $this->option('oda') : null, (bool) $this->option('pasif'), (bool) $this->option('deneme'));
            $this->info(($this->option('deneme') ? 'Deneme (hiçbir şey yazılmadı)' : 'Eczacılar üye panelinde')." · oda kurumu #{$sonuc['kurum']['id']} {$sonuc['kurum']['name']}");
            foreach (['eczacilar' => 'Eczacı', 'yoneticiler' => 'Oda yöneticisi'] as $ne => $etiket) {
                $s = $sonuc[$ne];
                $this->line(sprintf('  %-16s yeni üye %d · var olan %d · odaya katılan %d · pasife alınan %d', $etiket, $s['created'], $s['existing'], $s['joined'], $s['passive']));
            }
            $this->line(sprintf('  %-16s %d', 'Eczane ↔ üye', $sonuc['eczane_bagi']));
            $this->line(sprintf('  %-16s %d', 'Mazeret ↔ üye', $sonuc['mazeret_bagi']));
            foreach ($sonuc['uyarilar'] as $uyari) {
                $this->warn('  '.$uyari);
            }
        })->purpose('Eczacıların panel hesaplarını site üyesine taşır, odayı kurum yapar (Hesabım › Nöbetlerim)');
        Artisan::command('nobet:aktar {kaynak : mysql://kullanici:sifre@sunucu:3306/veritabani} {--ilce= : eski ilce_id (kaynakta birden çok ilçe varsa)} {--deneme : yazmadan dene, sonunda geri al} {--uyeler : yalnızca üyeleri aktar (nöbet verisi önceden aktarılmış siteye)}', function () {
            /** @var Command $this */
            $aktarim = new Aktarim(Aktarim::baglan((string) $this->argument('kaynak')));
            $deneme = (bool) $this->option('deneme');
            if ($this->option('uyeler')) {
                $sonuc = $aktarim->uyeleriAktar($deneme);
                $this->info($deneme ? 'Deneme (hiçbir şey yazılmadı) · üyeler' : 'Üyeler aktarıldı');
            } else {
                $ilce = $this->option('ilce') !== null ? (int) $this->option('ilce') : null;
                $sonuc = $aktarim->aktar($ilce, $deneme);
                $this->info(($deneme ? 'Deneme (hiçbir şey yazılmadı)' : 'Aktarıldı')." · ilçe {$sonuc['ilce']}");
            }
            foreach ($sonuc['ozet'] as $ne => $sayi) {
                $this->line(sprintf('  %-24s %d', $ne, $sayi));
            }
            foreach ($sonuc['uyarilar'] as $uyari) {
                $this->warn('  '.$uyari);
            }
        })->purpose('Eski eczaciodasi.net veritabanından nöbet verisini bu siteye aktarır');
    }

    /** Günlük: son döngünün bitişi yaklaşınca nöbeti yönetenlere hatırlatma (Donguler::hatirlat). */
    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static function () {
            if (Schema::hasTable(Nobetler::TABLO) && Schema::hasTable('notifications')) {
                (new Donguler)->hatirlat();
            }
        })->name('nobet:hatirlat')->dailyAt('09:00');
    }

    public function events(): array
    {
        $ad = static fn ($p) => (string) ($p['ad'] ?? '');

        return [
            'nobet.bolge_eklendi' => ['Bölge eklendi', 'success', $ad],
            'nobet.bolge_guncellendi' => ['Bölge güncellendi', 'primary', $ad],
            'nobet.bolge_silindi' => ['Bölge silindi', 'danger', $ad],
            'nobet.grup_eklendi' => ['Grup eklendi', 'success', $ad],
            'nobet.grup_guncellendi' => ['Grup güncellendi', 'primary', $ad],
            'nobet.grup_silindi' => ['Grup silindi', 'danger', $ad],
            'nobet.eczane_eklendi' => ['Eczane eklendi', 'success', $ad],
            'nobet.eczane_guncellendi' => ['Eczane güncellendi', 'primary', $ad],
            'nobet.eczane_silindi' => ['Eczane silindi', 'danger', $ad],
            'nobet.ozel_gun_eklendi' => ['Özel gün eklendi', 'success', $ad],
            'nobet.ozel_gun_guncellendi' => ['Özel gün güncellendi', 'primary', $ad],
            'nobet.ozel_gun_silindi' => ['Özel gün silindi', 'danger', $ad],
            'nobet.dongu_olusturuldu' => ['Nöbet döngüsü oluşturuldu', 'success', static fn ($p) => sprintf('%s · %d nöbet', $p['ad'] ?? '', $p['atama_sayisi'] ?? 0)],
            'nobet.dongu_silindi' => ['Nöbet döngüsü silindi', 'danger', static fn ($p) => sprintf('%s · %d nöbet', $p['ad'] ?? '', $p['silinen_nobet'] ?? 0)],
            // Yönetim arayüzünün uyum uçları (Veri\Uyum): tür ve ad yükte.
            'nobet.kayit_eklendi' => ['Kayıt eklendi', 'success', static fn ($p) => trim(($p['tur'] ?? '').': '.($p['ad'] ?? ''), ': ')],
            'nobet.kayit_guncellendi' => ['Kayıt güncellendi', 'primary', static fn ($p) => trim(($p['tur'] ?? '').': '.($p['ad'] ?? ''), ': ')],
            'nobet.toplu_eklendi' => ['Excel’den toplu eklendi', 'success', static fn ($p) => (string) ($p['ad'] ?? '')],
            'nobet.kayit_silindi' => ['Kayıt silindi', 'danger', static fn ($p) => trim(($p['tur'] ?? '').': '.($p['ad'] ?? ''), ': ')],
            'nobet.mazeret_talep_edildi' => ['Mazeret bildirildi', 'warning', $ad],
            'nobet.mazeret_talebi_geri_alindi' => ['Mazeret talebi geri alındı', 'neutral', $ad],
            'nobet.mazeret_karari' => ['Mazeret talebine karar verildi', 'primary', static fn ($p) => sprintf('%s: %s', $p['ad'] ?? '', ($p['karar'] ?? '') === 'onay' ? 'onaylandı' : 'reddedildi')],
            'nobet.takas_karari' => ['Nöbet takasına karar verildi', 'primary', static fn ($p) => sprintf('%s: %s', $p['ad'] ?? '', ($p['karar'] ?? '') === 'onay' ? 'onaylandı' : 'reddedildi')],
            'nobet.nobet_degistirildi' => ['Nöbet elle değiştirildi', 'warning', static fn ($p) => sprintf('%s: %s → %s', $p['tarih'] ?? '', $p['onceki'] ?? '', $p['yeni'] ?? '')],
        ];
    }
}
