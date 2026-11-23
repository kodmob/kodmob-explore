<?php

namespace KodmobTools\Paytr\Tests;

use App\Core\Modules;
use App\Core\Settings\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use KodmobTools\Paytr\PaytrGateway;
use KodmobTools\Paytr\PaytrModule;
use Tests\TestCase;

/**
 * PayTR paketi: ödeme jetonu (imzalı istek), hash denetimli bildirim, Mağaza'ya ve anahtarlarına bağlılık. Ağa
 * çıkılmaz (Http::fake).
 */
class PaytrTest extends TestCase
{
    private const ENV = 'blog,briefs,commerce';

    private string $number = '';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Mail::fake();
        $s = SettingsService::instance();
        $s->set(PaytrModule::MERCHANT_ID, '123456');
        $s->set(PaytrModule::MERCHANT_KEY, 'paytr-anahtar');
        $s->set(PaytrModule::MERCHANT_SALT, 'paytr-tuz');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    private function order(): array
    {
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '100', 'tax_rate' => '20', 'digital' => true, 'i18n' => ['tr' => ['title' => 'E-kitap']]], $h)->assertCreated()->json('id');
        $token = $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 2])->assertOk()->getCookie('km_cart', false)->getValue();
        $this->withUnencryptedCookie('km_cart', $token);
        $placed = $this->postJson('/v1/shop/checkout', ['name' => 'Ali Veli', 'email' => 'ali@ornek.test', 'payment' => 'paytr', 'terms' => true, 'locale' => 'tr'])->assertCreated()->json();
        $this->number = $placed['order']['number'];

        return $placed;
    }

    public function test_token_and_hash_checked_callback(): void
    {
        Http::fake(['www.paytr.com/*' => Http::response(['status' => 'success', 'token' => 'tok123'])]);
        $placed = $this->order();
        $this->assertSame('https://www.paytr.com/odeme/guvenli/tok123', $placed['payment']['redirect']);
        $oid = (string) DB::table('commerce_payments')->value('reference');
        $this->assertMatchesRegularExpression('/^'.str_replace('-', '', $this->number).'[0-9A-F]{6}$/', $oid);
        Http::assertSent(static function (HttpRequest $r) use ($oid) {
            $hash = $r['merchant_id'].$r['user_ip'].$r['merchant_oid'].$r['email'].$r['payment_amount'].$r['user_basket'].$r['no_installment'].$r['max_installment'].$r['currency'].$r['test_mode'];

            return $r->url() === PaytrGateway::TOKEN_API && $r['merchant_oid'] === $oid && $r['payment_amount'] === '20000' && $r['currency'] === 'TL'
                && $r['test_mode'] === '1' && $r['paytr_token'] === PaytrGateway::sign($hash.'paytr-tuz', 'paytr-anahtar')
                && json_decode(base64_decode($r['user_basket']), true) === [['E-kitap', '100.00', 2]];
        });

        $callback = fn (string $status, string $total, ?string $hash = null) => $this->post('/v1/shop/payments/paytr', [
            'merchant_oid' => $oid, 'status' => $status, 'total_amount' => $total,
            'hash' => $hash ?? PaytrGateway::sign($oid.'paytr-tuz'.$status.$total, 'paytr-anahtar'),
        ]);
        $callback('success', '20000', 'yanlis')->assertStatus(400);
        $this->assertSame('pending', DB::table('commerce_orders')->value('status'));
        $this->assertSame('OK', $callback('success', '20000')->assertOk()->getContent());
        $this->assertSame('paid', DB::table('commerce_orders')->value('status'));
    }

    public function test_needs_the_shop_and_its_keys(): void
    {
        $this->assertSame(['commerce'], Modules::requires('paytr'));
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '10', 'i18n' => ['tr' => ['title' => 'Ürün']]], $h)->json('id');
        $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 1])->assertOk()->assertJsonPath('payments.*.key', ['paytr']);
        SettingsService::instance()->set(PaytrModule::MERCHANT_SALT, '');
        $this->getJson('/v1/shop/cart')->assertJsonPath('payments', [], 'anahtarı eksik sağlayıcı seçilemez');
    }
}
