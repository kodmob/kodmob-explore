<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alt grup (eski readme madde 11): grubun üst grubu. Tek düzey; nöbet seçiminde alt grup üst grubuyla aynı sayılır
 * (aynı gün aynı bölgede, I ve J kurallarında).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nobet_gruplar', function (Blueprint $table) {
            $table->unsignedBigInteger('ust_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nobet_gruplar', function (Blueprint $table) {
            $table->dropColumn('ust_id');
        });
    }
};
