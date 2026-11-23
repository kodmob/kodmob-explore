# PayTR

Kodmob topluluk paketi: Kartla online ödeme (taksit isteğe bağlı): PayTR'nin güvenli ödeme sayfası ve hash denetimli bildirim. PayTR sahibinin ticari markasıdır; Kodmob onunla bağlantılı değildir.

Mağaza'ya takılıdır (`requires: ["commerce"]`); Mağaza kapalıyken çalışmaz. Derleme gerekmez.

## Kurulum

- **Panelden:** Ayarlar › Araç Pazarı › Keşfet › PayTR › **Kur** (katalogdan indirilir, özeti doğrulanır).
- **Komutla:** `php artisan kodmob:add paytr` (ya da bu klasör / zip).

## Ayarlar

Proje Ayarları › Mağaza: PayTR: mağaza no, parola, gizli anahtar, deneme kipi, taksit. PayTR mağaza panelinde Bildirim URL'si: `https://<siteniz>/api/v1/shop/payments/paytr`.

## Geliştirme

- `php/`: modül, ödeme yöntemi (`Payments::register`), bildirim ucu (`routes.php`)
- `tests/`: çatının "Extensions" test takımında koşar (paket `kodmob-core/extensions/paytr` altına bağlanınca):
  `php artisan test --testsuite=Extensions`
