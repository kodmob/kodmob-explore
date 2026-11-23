<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eczacı hesabı: eczanenin kullanıcısı (`kullanici_id`, bir kullanıcı bir eczane) ve mazeret talebine yöneticinin
 * kararı (onay | ret, not, kim, ne zaman).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->unique('kullanici_id'); // sütun 2026_11_10_000100 ile geldi
        });
        Schema::table('nobet_mazeretler', function (Blueprint $table) {
            $table->string('karar_notu', 500)->nullable();
            $table->unsignedBigInteger('karar_veren_id')->nullable();
            $table->dateTime('karar_tarihi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nobet_mazeretler', function (Blueprint $table) {
            $table->dropColumn(['karar_notu', 'karar_veren_id', 'karar_tarihi']);
        });
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->dropUnique(['kullanici_id']);
        });
    }
};
