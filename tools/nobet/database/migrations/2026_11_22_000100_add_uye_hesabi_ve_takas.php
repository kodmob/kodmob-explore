<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eczacı üye panelinde (Kodmob 3.2, Hesabım): eczanenin site üyesi (`uye_id`, bir üye bir eczane; panel hesabı
 * `kullanici_id` geçiş süresince kalır), mazereti bildiren ve karar veren üye, nöbet takası talepleri.
 *
 * Takas: iki eczane gelecek iki nöbetini değiştirir. Teklif eden ve karşı eczanenin nöbeti; durum teklif (karşı taraf
 * yanıt verecek) → kabul (oda yönetiminin onayı bekleniyor) → onay (nöbetler değişti) | ret | iptal. Nöbet kimliği
 * değişebilir (döngü silinirse), bu yüzden tarih ve eczaneler satırda da durur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->unsignedBigInteger('uye_id')->nullable()->unique();
        });
        Schema::table('nobet_mazeretler', function (Blueprint $table) {
            $table->unsignedBigInteger('uye_id')->nullable();
            $table->unsignedBigInteger('karar_veren_uye_id')->nullable();
        });
        Schema::create('nobet_takaslar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eczane_id')->index();
            $table->unsignedBigInteger('nobet_id');
            $table->date('tarih');
            $table->unsignedBigInteger('karsi_eczane_id')->index();
            $table->unsignedBigInteger('karsi_nobet_id');
            $table->date('karsi_tarih');
            $table->string('aciklama', 500)->default('');
            $table->string('durum', 10)->default('teklif')->index();
            $table->string('ret_eden', 10)->nullable();
            $table->string('karar_notu', 500)->nullable();
            $table->unsignedBigInteger('olusturan_uye_id')->nullable();
            $table->unsignedBigInteger('olusturan_id')->nullable();
            $table->unsignedBigInteger('karar_veren_uye_id')->nullable();
            $table->unsignedBigInteger('karar_veren_id')->nullable();
            $table->dateTime('yanit_tarihi')->nullable();
            $table->dateTime('karar_tarihi')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nobet_takaslar');
        Schema::table('nobet_mazeretler', function (Blueprint $table) {
            $table->dropColumn(['uye_id', 'karar_veren_uye_id']);
        });
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->dropUnique(['uye_id']);
            $table->dropColumn('uye_id');
        });
    }
};
