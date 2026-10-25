<?php

namespace KodmobTools\Netgsm\Tests;

use App\Core\Sms\SmsProviders;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use KodmobTools\Netgsm\NetgsmGateway;
use KodmobTools\Netgsm\NetgsmProvider;
use Tests\TestCase;

/** NetGSM paketi: çatıdaki sağlayıcının yerine geçer, NetGSM'e doğru isteği atar, hata kodlarını Türkçe açıklar. */
class NetgsmTest extends TestCase
{
    public function test_package_replaces_the_framework_provider(): void
    {
        $this->assertInstanceOf(NetgsmProvider::class, SmsProviders::get('netgsm'));
    }

    public function test_request_and_error_codes(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/system/sms', ['provider' => 'netgsm', 'values' => ['username' => '8503020000', 'password' => 'api-sifre', 'sender' => 'KODMOB']], $h)->assertOk();
        Http::fake([
            NetgsmGateway::URL.'/sms/rest/v2/msgheader*' => Http::response(['code' => '00', 'msgheaders' => ['KODMOB']]),
            NetgsmGateway::URL.'/sms/rest/v2/send' => Http::sequence()
                ->push(['code' => '00', 'jobid' => 'is-1'])
                ->push(['code' => '30'], 406),
        ]);

        $this->postJson('/v1/system/sms/senders', [], $h)->assertOk()->assertJsonPath('senders', ['KODMOB']);
        $this->postJson('/v1/system/sms/test', ['to' => '0532 123 45 67', 'message' => 'Deneme'], $h)->assertOk()->assertJsonPath('log.0.job', 'is-1');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sms/rest/v2/send')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('8503020000:api-sifre'))
            && $r['msgheader'] === 'KODMOB' && $r['messages'] === [['msg' => 'Deneme', 'no' => '5321234567']]);

        $this->postJson('/v1/system/sms/test', ['to' => '05321234567'], $h)->assertStatus(422)
            ->assertJsonPath('code', 'km_sms_failed')->assertJsonPath('message', fn ($m) => str_contains($m, 'şifre') && str_contains($m, '30'));
    }
}
