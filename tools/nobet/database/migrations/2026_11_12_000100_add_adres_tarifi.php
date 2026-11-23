<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Eczanenin adres tarifi (ör. "Devlet Hastanesi karşısı"); sitenin Nöbetçi Eczaneler sayfasında adresin altında. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->string('tarif', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nobet_eczaneler', function (Blueprint $table) {
            $table->dropColumn('tarif');
        });
    }
};
