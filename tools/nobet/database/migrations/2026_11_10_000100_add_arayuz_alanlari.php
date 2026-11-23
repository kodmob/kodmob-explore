<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eczane yönetim arayüzünün (eski panel, kodmob/eczane/admin) alanları: eczanenin adresi parçalı (il, ilçe, mahalle,
 * cadde, bina no; `adres` bunlardan birleşir), takvimdeki rengi ve eczacının kullanıcı hesabı; grubun bölgesi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->string('sehir', 80)->nullable();
            $table->string('ilce', 80)->nullable();
            $table->string('mahalle', 150)->nullable();
            $table->string('cadde', 150)->nullable();
            $table->string('binano', 30)->nullable();
            $table->string('renk', 20)->nullable();
            $table->unsignedBigInteger('kullanici_id')->nullable();
        });
        Schema::table('nobet_gruplar', function (Blueprint $table) {
            $table->unsignedBigInteger('bolge_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->dropColumn(['sehir', 'ilce', 'mahalle', 'cadde', 'binano', 'renk', 'kullanici_id']);
        });
        Schema::table('nobet_gruplar', function (Blueprint $table) {
            $table->dropColumn('bolge_id');
        });
    }
};
