<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odanın ilçesinin mahalle ve caddeleri (eczane adres formu ve Adres Ekleme sayfası). Eski sistemde bütün Türkiye
 * (50 bin mahalle, 570 bin cadde) her odanın veritabanındaydı; burada yalnızca odanın ilçesi (aktarımda ya da
 * sayfadan eklenir). `anahtar` eski sistemin mahalle_key'i: caddeler mahalleye onunla bağlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nobet_mahalleler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 150);
            $table->unsignedBigInteger('anahtar')->unique();
            $table->unsignedInteger('ilce_key')->index();
            $table->dateTime('created_at');
        });

        Schema::create('nobet_caddeler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 150);
            $table->unsignedBigInteger('mahalle_anahtar')->index();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nobet_caddeler');
        Schema::dropIfExists('nobet_mahalleler');
    }
};
