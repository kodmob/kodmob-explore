<?php

namespace KodmobTools\Netgsm;

use App\Core\Module;
use App\Core\Sms\SmsProviders;

/**
 * NetGSM SMS paketi: SMS aracına (`requires: ["sms"]`) NetGSM sağlayıcısını kaydeder. Kodmob çatısında da yerleşik bir
 * NetGSM sağlayıcısı vardır; bu paket aynı anahtarla (`netgsm`) kaydolduğu için kurulunca onun yerine geçer. Böylece
 * NetGSM'in API'sindeki bir değişiklik çatının yeni sürümünü beklemeden bu paketle gelir. Ayarlar aynıdır (Sistem
 * Ayarları › SMS), kaydedilmiş bilgiler korunur.
 */
final class NetgsmModule extends Module
{
    public function boot(): void
    {
        SmsProviders::register(new NetgsmProvider);
    }
}
