<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mazeretler: bir güne bağlı not (eski sistem: Nöbet Kayıtları'nda nöbet değiştirilirken yazılan açıklama). `eczane_id`
 * ve `durum` ileride eczacının mazeret talebi ve yöneticinin onayı için (kayit | talep | onay | ret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nobet_mazeretler', function (Blueprint $table) {
            $table->id();
            $table->date('tarih')->index();
            $table->string('aciklama', 500);
            $table->string('tur', 30)->default('mazeret');
            $table->unsignedBigInteger('eczane_id')->nullable()->index();
            $table->string('durum', 10)->default('kayit');
            $table->unsignedBigInteger('olusturan_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nobet_mazeretler');
    }
};
