# NetGSM SMS

Kodmob'un SMS aracına NetGSM sağlayıcısı (REST v2: `api.netgsm.com.tr`). Kullanıcılar sayfasındaki SMS düğmeleri,
deneme SMS'i ve gönderim kaydı bununla çalışır.

Kodmob çatısında yerleşik bir NetGSM sağlayıcısı da vardır. Bu paket aynı anahtarla (`netgsm`) kaydolur ve kurulunca
onun yerine geçer: NetGSM'in API'sinde bir değişiklik olduğunda çatının yeni sürümünü beklemeden bu paket güncellenir.
Ayarlar aynıdır, kayıtlı bilgiler korunur.

## Kurulum

```
cd kodmob-core
php artisan kodmob:add netgsm          # Keşfet kataloğundan (sha256 denetlenir)
```

Sonra Araç Pazarı'nda SMS aracı açık olmalı (`requires: ["sms"]`). Sistem Ayarları › SMS › Sağlayıcı: **NetGSM**.

## Ayarlar

| Alan | Açıklama |
|---|---|
| Kullanıcı adı | Abone numarası (850…) ya da API izni verilmiş alt kullanıcı |
| API şifresi | Mühürlü saklanır, panele geri gönderilmez |
| Gönderici başlığı | NetGSM'de onaylandığı gibi; "Bağlantıyı dene" onaylı başlıkları getirir |
| İleti türü (İYS) | Bilgilendirme, ya da İYS kontrollü ticari ileti (bireysel / tacir) |
| Uygulama adı | NetGSM geliştirici hesabındaki `appname` (isteğe bağlı) |

NetGSM panelinde IP kısıtlaması açıksa sitenin sunucu IP adresini izinli listeye ekleyin. Hata kodları (30: kimlik,
40: başlık, 50/51: İYS, 85: dakikalık sınır…) panelde Türkçe açıklamayla gösterilir.

## Geliştirme

- `php/NetgsmModule.php`: `boot()`'ta `SmsProviders::register(new NetgsmProvider)`
- `php/NetgsmProvider.php`: alanlar (Sistem Ayarları › SMS formu bunlardan çizilir)
- `php/NetgsmGateway.php`: NetGSM REST v2 istekleri ve hata kodları
- `tests/`: `php artisan test` (paket `kodmob-core/extensions/netgsm` altındayken)
