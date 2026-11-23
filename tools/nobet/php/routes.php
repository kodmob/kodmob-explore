<?php

/*
 * Nöbet'in uçları: /api/v1 altında yüklenir; araç bu projede kapalıyken 404 (`module:nobet`).
 * Panel ucu `auth.jwt` + `cap:`; yazma rotalarının adı modülün events()'inde (İşlem Geçmişi).
 */

use Illuminate\Support\Facades\Route;
use KodmobTools\Nobet\Http\EczacimController;
use KodmobTools\Nobet\Http\HesapController;
use KodmobTools\Nobet\Http\NobetAdminController as C;
use KodmobTools\Nobet\Http\NobetSiteController;
use KodmobTools\Nobet\Http\OdaController;
use KodmobTools\Nobet\Http\TakasController;
use KodmobTools\Nobet\Http\UyelerController;
use KodmobTools\Nobet\Http\UyumController;
use KodmobTools\Nobet\NobetModule;
use KodmobTools\Nobet\Veri\Uyum;

// Herkese açık: sitenin Nöbetçi Eczaneler sayfası (kamuya açık bilgi; gerekçesi tanımın `public`'inde).
Route::get('nobet/nobetciler', [NobetSiteController::class, 'nobetciler'])->middleware('throttle:60,1');
// Herkese açık: odanın adı, il / ilçe kodu, harita merkezi (yönetim arayüzünün giriş sayfası da okur).
Route::get('nobet/oda', [UyumController::class, 'oda'])->middleware('throttle:120,1');

// Üye paneli (Hesabım; site üyesi): Nöbetlerim ve Oda yönetimi. `members/` altında: üyenin oturum çerezi (km_member)
// yalnızca /api/v1/members yoluna gider (Mesajlar, Sosyal de böyle). Oturum ve oda rolü denetleyicide.
Route::prefix('members/nobet')->group(function () {
    Route::get('/', [HesapController::class, 'ozet'])->middleware('throttle:120,1');
    Route::post('mazeret', [HesapController::class, 'mazeret'])->middleware('throttle:20,1');
    Route::delete('mazeret/{id}', [HesapController::class, 'mazeretGeriAl'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('takas-adaylari', [HesapController::class, 'adaylar'])->middleware('throttle:60,1');
    Route::post('takas', [HesapController::class, 'takas'])->middleware('throttle:20,1');
    Route::post('takas/{id}/yanit', [HesapController::class, 'yanit'])->whereNumber('id')->middleware('throttle:20,1');
    Route::delete('takas/{id}', [HesapController::class, 'takasIptal'])->whereNumber('id')->middleware('throttle:20,1');
});
Route::prefix('members/nobet/oda')->group(function () {
    Route::get('/', [OdaController::class, 'ozet'])->middleware('throttle:120,1');
    Route::post('mazeret/{id}/karar', [OdaController::class, 'mazeretKarari'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('takas/{id}/karar', [OdaController::class, 'takasKarari'])->whereNumber('id')->middleware('throttle:30,1');
    Route::put('eczaneler/{id}/uye', [OdaController::class, 'bagla'])->whereNumber('id')->middleware('throttle:30,1');
});

// Yönetim arayüzünün (eski panel) uyum uçları: tablo adı izin listesinde (Veri\Uyum).
$okunur = implode('|', Uyum::OKUNUR);
$yazilir = implode('|', Uyum::YAZILIR).'|donguler';

Route::middleware(['auth.jwt', 'two_factor', 'activity', 'cap:km_access_panel'])->prefix('admin/nobet')->group(function () use ($okunur, $yazilir) {
    Route::middleware('cap:'.NobetModule::GORME)->group(function () use ($okunur) {
        Route::get('kayitlar', [C::class, 'kayitlar']);
        Route::get('takvim', [C::class, 'takvim']);
        Route::get('donguler', [C::class, 'donguler']);
        Route::get('eski/{tablo}', [UyumController::class, 'liste'])->where('tablo', $okunur);
        Route::get('eski/{tablo}/{id}', [UyumController::class, 'bul'])->where('tablo', $okunur)->whereNumber('id');
        // Eczacının alanı (Nöbetlerim): hesabına bağlı eczane; bağlı değilse talep 403.
        Route::get('benim', [EczacimController::class, 'ozet']);
        Route::post('benim/mazeret', [EczacimController::class, 'talep'])->middleware('throttle:20,1')->name('nobet.mazeret_talep_edildi');
        Route::delete('benim/mazeret/{id}', [EczacimController::class, 'geriAl'])->whereNumber('id')->name('nobet.mazeret_talebi_geri_alindi');
        Route::get('takaslar', [TakasController::class, 'liste']);
    });

    Route::middleware('cap:'.NobetModule::YONETME)->group(function () use ($yazilir) {
        Route::post('eski/{tablo}/toplu', [UyumController::class, 'toplu'])->where('tablo', implode('|', Uyum::TOPLU))->middleware('throttle:10,1')->name('nobet.toplu_eklendi');
        Route::post('eski/{tablo}', [UyumController::class, 'ekle'])->where('tablo', $yazilir)->name('nobet.kayit_eklendi');
        Route::put('eski/{tablo}/{id}', [UyumController::class, 'guncelle'])->where('tablo', $yazilir)->whereNumber('id')->name('nobet.kayit_guncellendi');
        Route::delete('eski/{tablo}/{id}', [UyumController::class, 'sil'])->where('tablo', $yazilir)->whereNumber('id')->name('nobet.kayit_silindi');
    });

    // Üyeler: Kodmob kullanıcıları (Veri\Uyeler); yetkisi kullanıcı yetkileri. Rota adı yok: olay türünü denetleyici verir.
    Route::middleware('cap:km_view_users')->group(function () {
        Route::get('eski/uyeler', [UyelerController::class, 'liste']);
        Route::get('eski/uyeler/{id}', [UyelerController::class, 'bul'])->whereNumber('id');
    });
    Route::middleware('cap:km_manage_users')->group(function () {
        Route::post('eski/uyeler', [UyelerController::class, 'ekle'])->middleware('throttle:30,1');
        Route::put('eski/uyeler/{id}', [UyelerController::class, 'guncelle'])->whereNumber('id');
        Route::delete('eski/uyeler/{id}', [UyelerController::class, 'sil'])->whereNumber('id');
    });

    Route::middleware('cap:'.NobetModule::YONETME)->group(function () {
        $olay = ['bolgeler' => 'bolge', 'gruplar' => 'grup', 'eczaneler' => 'eczane', 'ozel-gunler' => 'ozel_gun'];
        foreach ($olay as $tur => $ad) {
            Route::post($tur, [C::class, 'kaydet'])->defaults('tur', $tur)->name("nobet.{$ad}_eklendi");
            Route::put("{$tur}/{id}", [C::class, 'kaydet'])->defaults('tur', $tur)->whereNumber('id')->name("nobet.{$ad}_guncellendi");
            Route::delete("{$tur}/{id}", [C::class, 'sil'])->defaults('tur', $tur)->whereNumber('id')->name("nobet.{$ad}_silindi");
        }
        Route::post('donguler/onizle', [C::class, 'onizle'])->middleware('throttle:30,1')->defaults('activity', false);
        Route::post('donguler', [C::class, 'olustur'])->middleware('throttle:10,1')->name('nobet.dongu_olusturuldu');
        Route::delete('donguler/{id}', [C::class, 'donguSil'])->whereNumber('id')->name('nobet.dongu_silindi');
        Route::put('nobetler/{id}', [C::class, 'degistir'])->whereNumber('id')->name('nobet.nobet_degistirildi');
        Route::post('mazeretler/{id}/karar', [EczacimController::class, 'karar'])->whereNumber('id')->name('nobet.mazeret_karari');
        Route::post('takaslar/{id}/karar', [TakasController::class, 'karar'])->whereNumber('id')->name('nobet.takas_karari');
    });
});
