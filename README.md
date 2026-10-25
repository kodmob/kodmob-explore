# Kodmob Keşfet

[Kodmob](https://github.com/kodmob/kodmob) panelindeki **Araç Pazarı › Keşfet**'in topluluk araçları kataloğu. Bu depoya
eklenen her araç paketi, Kodmob kurulumlarının Keşfet sayfasında kendiliğinden listelenir.

> **In English:** the community tool catalog for Kodmob's panel (*Tools › Discover*). Add your tool under
> `tools/<key>/` with a pull request; CI validates it, builds `dist/<key>-<version>.zip` and updates `catalog.json`,
> which every Kodmob installation reads. Install with `php artisan kodmob:add <key>` (the zip's sha256 is verified).

## Nasıl çalışır

```
tools/<anahtar>/          araç paketi (kodmob-tool.json, php/, panel/, tests/, README.md, LICENSE)
dist/<anahtar>-<sürüm>.zip  CI'nin ürettiği paket (elle eklenmez)
catalog.json              CI'nin ürettiği katalog: ad, açıklama, sürüm, uyumlu çatı, indirme adresi, sha256
verified.json             incelenmiş sürümler ("anahtar@sürüm"; Keşfet'te "Doğrulanmış" rozeti)
```

1. Araç `tools/<anahtar>/` klasörüne eklenir (ya da sürümü yükseltilir), çekme isteği açılır.
2. CI paketi denetler (`node scripts/build-catalog.mjs --check`): tanım alanları, sürüm, uyumlu çatı, modül sınıfı.
3. Birleştirilince CI zip'i ve `catalog.json`'u üretip depoya yazar.
4. Kodmob kurulumları kataloğu birkaç saatte bir okur (Araç Pazarı › Keşfet › **Yenile** hemen okur). Kurulum
   geliştirme bilgisayarında: `php artisan kodmob:add <anahtar>`, sonra derleme ve yayın.

## Araç eklemek

```bash
# Kodmob deposunda iskelet:
cd kodmob-core && php artisan kodmob:tool new stok-takibi --label="Stok takibi"
php artisan kodmob:tool check stok-takibi      # tanım, testler
# Klasörü bu deponun tools/ altına kopyalayın, çekme isteği açın.
```

Paket biçimi, güvenlik kuralları ve kontrol listesi: Kodmob'un
[docs/arac-paketi.md](https://github.com/kodmob/kodmob/blob/main/docs/arac-paketi.md) belgesi. Kısaca:

- Anahtar küçük harf, rakam ve tire; klasörün adı anahtarla aynı. Ad alanı kendi adınızla (`KodmobTools\…`).
- `kodmob` alanı uyumlu çatı sürümü (ör. `">=3.0 <4"`); uyumsuz kurulumda Keşfet "Bu sürümle uyumsuz" der.
- Kişisel veri taşıyan tablo yedeğe `personal: true` ile girer; gizli değer `format: 'secret'` ayarla saklanır.
- Her sürüm yükseltmesinde `version` artar: eski zip katalogdan kalkar.

## Lisans

Depo MIT. Her araç kendi `LICENSE` dosyasıyla yayımlanır.
