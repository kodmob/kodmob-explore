# Nöbet

Kodmob aracı: eczacı odasının nöbet sistemi. Kurallarla adil nöbet döngüsü, takvim ve sitede Nöbetçi Eczaneler sayfası. Eczacılar için Hesabım'da Nöbetlerim (mazeret, takas), oda yöneticisi için Oda yönetimi.

## Eczacılar üye panelinde (Kodmob 3.2)

Eczacı site üyesidir, oda bu sitedeki bir kurumdur (ayar `nobet.kurum`). Planlama işleri (döngü, eczaneler, bölgeler,
özel günler) yönetim arayüzünde (`/admin/`) kalır.

- **Hesabım › Nöbetlerim** (`nobet-nobetlerim`; eczaneye bağlı üye ya da odanın üyesi): eczane, gelecek nöbetler,
  mazeret bildirme, nöbet takası (teklif, gelen teklife yanıt, geri alma).
- **Hesabım › Oda yönetimi** (`nobet-oda`; odanın kurumu seçiliyken, kurumun yöneticisi ya da sahibi): mazeret ve takas
  kararları, eczane ↔ hesap bağı. Yönetim arayüzünün Mazeretler sayfası da takasa karar verir.
- **Takas:** teklif → karşı eczane kabul eder → oda onaylar (ayar `nobet.takas_onayi`; kapalıysa kabulde uygulanır).
  Aynı bölge ve vardiya, başka gün; takastan sonra bir eczane aynı gün iki kez nöbetçi olmaz.
- **Uçlar** `members/nobet/…` altında: üyenin oturum çerezi yalnızca `/api/v1/members` yoluna gider.
- **Geçiş:** `php artisan nobet:uyelere [--oda="…"] [--pasif] [--deneme]`. Eczacı panel hesapları üye olur, aynı e-posta
  ve şifreyle girer. Oda yöneticileri kuruma yönetici olarak katılır, panel hesapları açık kalır. Eczane ve mazeret
  bağları e-postayla taşınır. Komut ikinci kez çalışırsa zarar vermez.

## Kurulum

- **Panelden:** Ayarlar › Araç Pazarı › Keşfet › Zip'ten yükle (zip: `php artisan kodmob:tool pack <bu klasör>`).
- **Komutla:** `cd kodmob-core && php artisan kodmob:add <bu paketin klasörü ya da zip'i>`

Derleme gerekmez; tabloları her projede kurulur. Kurulunca Araç Pazarı'nda görünür; her projede ayrı açılıp kapanır.

## Geliştirme

- `php/`: modül (`NobetModule`: yetkiler, ayarlar, olaylar), uçlar (`routes.php`), controller'lar
- `database/migrations/`: tablolar (SQLite, MySQL ve PostgreSQL'de çalışacak biçimde)
- `panel/`: sayfalar (tanımın `panel` listesi): hazır ES modülü (panel SDK'sı `/panel/sdk.js`) ve düz HTML + Alpine
  şablon; çok dosyalı kaynak `panel/src/`'de yazılıp `npm run module:build -- <klasör>` ile derlenir
- `site/`: sitedeki sayfa (tanımın `site.routes`'u): dil başına HTML parçası (`nobet.tr.html`; temanın genel kabuğuna
  yerleşir, `km://route/home` sitenin adresine çevrilir) ve site SDK'sını (`/sdk/site.js`) kullanan betik; Nunjucks
  kaynağı `site/src/` + `npm run module:build`
- `blocks/`: Sayfa düzenleyicinin blokları (`block.json` + `block.html`); varsayılan metin `i18n/<dil>.json`'dan
- `tests/`: `cd kodmob-core && php artisan test --filter=Nobet`

Kurallar ve katkı kontrol listesi: Kodmob'un `docs/arac-paketi.md` belgesi.

## Lisans

MIT, bkz. LICENSE.
