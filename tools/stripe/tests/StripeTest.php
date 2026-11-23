<?php

namespace KodmobTools\Stripe\Tests;

use App\Core\Modules;
use App\Core\Settings\SettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use KodmobTools\Stripe\StripeModule;
use Tests\TestCase;

/**
 * Stripe paketi: ödeme oturumu, imzalı bildirim, gizli anahtarın sunucudan çıkmaması, Mağaza'ya bağlılık. Ağa
 * çıkılmaz (Http::fake).
 */
class StripeTest extends TestCase
{
    private const ENV = 'blog,briefs,commerce';

    /** Son siparişin numarası. */
    private string $number = '';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Mail::fake();
        SettingsService::instance()->set(StripeModule::SECRET_KEY, 'sk_test_gizli');
        SettingsService::instance()->set(StripeModule::WEBHOOK_SECRET, 'whsec_gizli');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    /** Sepette 2 × 100,00 dijital ürün (kargo yok), Stripe ile sipariş. */
    private function order(): array
    {
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '100', 'tax_rate' => '20', 'digital' => true, 'i18n' => ['tr' => ['title' => 'E-kitap']]], $h)->assertCreated()->json('id');
        $token = $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 2])->assertOk()->getCookie('km_cart', false)->getValue();
        $this->withUnencryptedCookie('km_cart', $token);
        $placed = $this->postJson('/v1/shop/checkout', ['name' => 'Ali Veli', 'email' => 'ali@ornek.test', 'payment' => 'stripe', 'terms' => true, 'locale' => 'tr'])->assertCreated()->json();
        $this->number = $placed['order']['number'];

        return $placed;
    }

    private function event(string $session, int $amount, ?int $time = null, string $secret = 'whsec_gizli'): TestResponse
    {
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $session, 'payment_status' => 'paid', 'amount_total' => $amount, 'metadata' => ['order' => $this->number]]]]);
        $time ??= time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);

        return $this->call('POST', '/v1/shop/payments/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_secret_settings_never_leave_the_server(): void
    {
        $h = $this->admin();
        $this->assertStringStartsWith('enc:', (string) DB::table('settings')->where('setting_key', StripeModule::SECRET_KEY)->value('value'), 'mühürlü');
        $item = collect($this->getJson('/v1/settings', $h)->assertOk()->json('items'))->firstWhere('key', StripeModule::SECRET_KEY);
        $this->assertSame(['', true, 'secret'], [$item['value'], $item['set'], $item['format']]);
        $this->putJson('/v1/settings', ['values' => [StripeModule::SECRET_KEY => 'sk_test_yeni']], $h)->assertOk();
        $this->assertStringNotContainsString('sk_test', (string) DB::table('events')->where('event_type', 'settings.updated')->value('payload'), 'işlem geçmişinde maskeli');
        $this->assertArrayNotHasKey(StripeModule::SECRET_KEY, $this->getJson('/v1/admin/backup', $h)->json('settings') ?? []);
    }

    public function test_checkout_session_and_signed_webhook(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $placed = $this->order();
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $placed['payment']['redirect']);
        Http::assertSent(static fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r->hasHeader('Authorization', 'Bearer sk_test_gizli')
            && $r['line_items'][0]['price_data']['unit_amount'] == 20000 && $r['line_items'][0]['price_data']['currency'] === 'try'
            && str_contains($r['success_url'], 'odeme=tamam'));
        $this->assertSame('cs_test_1', DB::table('commerce_payments')->value('reference'));

        // İmzasız, yanlış anahtarla ya da eski zamanla gelen bildirim reddedilir; eksik tutar ödendi saymaz.
        $this->postJson('/v1/shop/payments/stripe', ['type' => 'checkout.session.completed'])->assertStatus(400);
        $this->event('cs_test_1', 20000, null, 'whsec_baska')->assertStatus(400);
        $this->event('cs_test_1', 20000, time() - 3600)->assertStatus(400);
        $this->event('cs_test_1', 100)->assertOk();
        $this->assertSame('pending', DB::table('commerce_orders')->value('status'));

        $this->event('cs_test_1', 20000)->assertOk()->assertJsonPath('received', true);
        $this->event('cs_test_1', 20000)->assertOk();
        $this->assertSame(['paid', 'paid'], [DB::table('commerce_orders')->value('status'), DB::table('commerce_orders')->value('payment_status')]);
        $this->assertSame(1, DB::table('events')->where('event_type', 'commerce.payment_received')->count(), 'ikinci bildirim etkisiz');
    }

    public function test_needs_the_shop_and_its_key(): void
    {
        $this->assertSame(['commerce'], Modules::requires('stripe'));
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '10', 'i18n' => ['tr' => ['title' => 'Ürün']]], $h)->json('id');
        $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 1])->assertOk()->assertJsonPath('payments.*.key', ['stripe']);
        SettingsService::instance()->set(StripeModule::SECRET_KEY, '');
        $this->getJson('/v1/shop/cart')->assertJsonPath('payments', [], 'anahtarı eksik sağlayıcı seçilemez');

        // Mağaza kapanınca ona takılı paket de çalışmaz.
        $this->putJson('/v1/system/tools/commerce', ['enabled' => false], $h)->assertOk();
        $this->assertNotContains('stripe', Modules::keys());
        $this->postJson('/v1/shop/payments/stripe')->assertNotFound();
    }
}
