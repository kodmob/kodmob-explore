<?php

/*
 * Stripe paketinin uçları: /api/v1 altında, araç kapalıyken (ya da Mağaza kapalıyken) 404 (`module:stripe`).
 * Ödeme sağlayıcısının bildirimi herkese açıktır; kimliği imza / hash ile denetlenir (kodmob-tool.json `public`).
 */

use Illuminate\Support\Facades\Route;
use KodmobTools\Stripe\StripeWebhook;

Route::post('shop/payments/stripe', StripeWebhook::class)->middleware('throttle:120,1');
