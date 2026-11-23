<?php

/*
 * PayTR paketinin uçları: /api/v1 altında, araç kapalıyken (ya da Mağaza kapalıyken) 404 (`module:paytr`).
 * Ödeme sağlayıcısının bildirimi herkese açıktır; kimliği imza / hash ile denetlenir (kodmob-tool.json `public`).
 */

use Illuminate\Support\Facades\Route;
use KodmobTools\Paytr\PaytrCallback;

Route::post('shop/payments/paytr', PaytrCallback::class)->middleware('throttle:120,1');
