<?php

namespace KodmobTools\Nobet\Tests;

use App\Core\Security\Roles;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\Veri\Uyeler;
use Tests\TestCase;

/** Eski Üyeler sayfası → Kodmob kullanıcıları: iki rol, eski alan adları, pasife alma, yetki sınırları. */
class UyelerTest extends TestCase
{
    private const API = '/v1/admin/nobet/eski/uyeler';

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('nobet_bolgeler')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true]);
        }
    }

    public function test_roller_hazir_gelir_yetkileri_dogru(): void
    {
        Uyeler::roller();
        $this->assertSame('Oda yöneticisi', Roles::get(Uyeler::YONETICI)['label']);
        $this->assertContains('km_manage_nobet', Roles::capsOf(Uyeler::YONETICI));
        $this->assertContains('km_manage_users', Roles::capsOf(Uyeler::YONETICI));
        $this->assertContains('km_view_nobet', Roles::capsOf(Uyeler::ECZACI));
        $this->assertNotContains('km_manage_nobet', Roles::capsOf(Uyeler::ECZACI));
        $this->assertNotContains('km_manage_roles', Roles::capsOf(Uyeler::YONETICI));
    }

    public function test_uye_eski_alanlarla_eklenir_guncellenir_pasife_alinir(): void
    {
        $h = $this->admin();
        $uye = $this->postJson(self::API, ['body' => [
            'adi' => 'Ayşe Yılmaz', 'sifre' => 'Nobet-Sifresi-2026', 'eposta' => 'ayse.yilmaz@eczane.test', 'telefon' => '0532 111 22 33', 'rol' => '1', 'aktivasyon' => 1,
        ]], $h)->assertOk()->json();
        $this->assertSame(['Ayşe Yılmaz', 'ayse.yilmaz', 'ayse.yilmaz@eczane.test', 1, 'Oda yöneticisi', 1],
            [$uye['adi'], $uye['kullanici'], $uye['eposta'], $uye['rol'], $uye['rol_adi'], $uye['aktivasyon']]);

        // Kısmi güncelleme: yalnızca rol; ad ve e-posta korunur.
        $this->putJson(self::API."/{$uye['id']}", ['body' => ['rol' => '0']], $h)->assertOk()->assertJson(['rol' => 0, 'rol_adi' => 'Eczacı', 'adi' => 'Ayşe Yılmaz']);
        // Yeni kullanıcı e-postayla giriş yapar.
        $this->postJson('/v1/auth/token', ['username' => 'ayse.yilmaz@eczane.test', 'password' => 'Nobet-Sifresi-2026'])->assertOk();

        $this->deleteJson(self::API."/{$uye['id']}", [], $h)->assertOk()->assertJson(['deleted' => true, 'aktivasyon' => 0]);
        $this->assertSame(User::PASSIVE, User::query()->find($uye['id'])->status);
        $this->putJson(self::API."/{$uye['id']}", ['body' => ['aktivasyon' => '1']], $h)->assertOk()->assertJson(['aktivasyon' => 1]);

        $turler = array_column($this->getJson('/v1/history?scope=all', $h)->json('items'), 'type');
        foreach (['users.created', 'users.updated', 'users.deactivated', 'users.activated'] as $tur) {
            $this->assertContains($tur, $turler);
        }
    }

    public function test_dogrulama_ve_sifre_politikasi_kodmobdan(): void
    {
        $h = $this->admin();
        $this->postJson(self::API, ['body' => ['adi' => 'X', 'sifre' => '1', 'eposta' => 'yok']], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.email', 'Geçerli bir e-posta adresi yazın.');
        $this->postJson(self::API, ['body' => ['adi' => 'Ali Veli', 'sifre' => '1', 'eposta' => 'ali@eczane.test']], $h)->assertStatus(422);
        $this->assertFalse(User::query()->where('email', 'ali@eczane.test')->exists());
    }

    public function test_yonetici_hesabi_listede_yok_buradan_duzenlenemez_eczaci_yazamaz(): void
    {
        $h = $this->admin();
        $admin = User::query()->where('role', User::ADMIN)->first();
        $this->getJson(self::API, $h)->assertOk()->assertExactJson([]);
        $this->putJson(self::API."/{$admin->id}", ['body' => ['adi' => 'Değişti']], $h)->assertNotFound();

        // Oda yöneticisi üye ekler ama Yönetici yapamaz (rol yalnızca iki rolden biri olur).
        Uyeler::roller();
        $oy = $this->as(Uyeler::YONETICI, 'odayonetici');
        $id = $this->postJson(self::API, ['body' => ['adi' => 'Mehmet', 'sifre' => 'Nobet-Sifresi-2026', 'eposta' => 'mehmet@eczane.test', 'rol' => 'admin']], $oy)
            ->assertOk()->assertJson(['rol' => 0])->json('id');
        $this->assertSame(Uyeler::ECZACI, User::query()->find($id)->role);

        $ec = $this->as(Uyeler::ECZACI, 'eczaci1');
        $this->getJson(self::API, $ec)->assertForbidden();
        $this->postJson(self::API, ['body' => ['adi' => 'Z', 'eposta' => 'z@eczane.test', 'sifre' => 'Nobet-Sifresi-2026']], $ec)->assertForbidden();
        $this->getJson('/v1/admin/nobet/eski/bolgeler', $ec)->assertOk();
        $this->postJson('/v1/admin/nobet/eski/bolgeler', ['body' => ['bolge_title' => 'Q']], $ec)->assertForbidden();
    }

    public function test_kullanici_adi_eposta_ya_da_addan_turetilir_cakisinca_numaralanir(): void
    {
        $this->assertSame('ayse.yilmaz', Uyeler::kullaniciAdi('Ayşe.Yılmaz@x.test', ''));
        $this->assertSame('cagri.ozturk', Uyeler::kullaniciAdi('', 'Çağrı Öztürk'));
        DB::table('users')->insert(['username' => 'ali', 'email' => 'ali@x.test', 'display_name' => 'Ali', 'password' => Hash::make('x'), 'role' => 'eczaci', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('ali2', Uyeler::kullaniciAdi('ali@y.test', ''));
        $this->assertSame('ab0', Uyeler::kullaniciAdi('ab@y.test', ''));
    }
}
