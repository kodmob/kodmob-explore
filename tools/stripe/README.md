# Stripe

Kodmob topluluk paketi: Kartla online ödeme: Stripe'ın ödeme sayfası ve imzalı bildirim. Stripe sahibinin ticari markasıdır; Kodmob onunla bağlantılı değildir.

Mağaza'ya takılıdır (`requires: ["commerce"]`); Mağaza kapalıyken çalışmaz. Derleme gerekmez.

## Kurulum

- **Panelden:** Ayarlar › Araç Pazarı › Keşfet › Stripe › **Kur** (katalogdan indirilir, özeti doğrulanır).
- **Komutla:** `php artisan kodmob:add stripe` (ya da bu klasör / zip).

## Ayarlar

Proje Ayarları › Mağaza: Stripe: gizli anahtar (sk_…) ve bildirim imza anahtarı (whsec_…). Stripe panelinde Webhooks › adres `https://<siteniz>/api/v1/shop/payments/stripe`, olay `checkout.session.completed`.

## Geliştirme

- `php/`: modül, ödeme yöntemi (`Payments::register`), bildirim ucu (`routes.php`)
- `tests/`: çatının "Extensions" test takımında koşar (paket `kodmob-core/extensions/stripe` altına bağlanınca):
  `php artisan test --testsuite=Extensions`
