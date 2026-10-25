# Merhaba (örnek araç)

Kodmob araç paketinin en küçük örneği: panelde bir sayfa (`panel/`), modülün yetkisi ve bir uç (`php/`), testi
(`tests/`). `php artisan kodmob:tool new <ad>` çıktısıyla aynıdır; kendi aracınızı yazarken başlangıç olarak bakın.

## Kurulum

```
cd kodmob-core
php artisan kodmob:add merhaba          # Keşfet kataloğundan (ya da paketin klasörü / zip'i)
php artisan migrate          # aracın tabloları varsa
cd ../kodmob-frontend && npm run build    # panel sayfası derlemeye girer
```

Kurulunca Araç Pazarı'nda görünür; her projede ayrı açılıp kapanır.

## Geliştirme

- `php/`: modül (`MerhabaModule`: yetkiler, ayarlar, olaylar), uçlar (`routes.php`), controller'lar
- `database/migrations/`: tablolar (SQLite, MySQL ve PostgreSQL'de çalışacak biçimde)
- `panel/`: sayfalar (`index.js` `PAGES`), bileşenler, şablonlar
- `tests/`: `cd kodmob-core && php artisan test --filter=Merhaba`

Kurallar ve katkı kontrol listesi: Kodmob'un `docs/arac-paketi.md` belgesi.

## Lisans

MIT, bkz. LICENSE.
