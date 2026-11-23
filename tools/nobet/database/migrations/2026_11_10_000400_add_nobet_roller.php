<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KodmobTools\Nobet\Veri\Uyeler;

/**
 * Odanın iki rolü: Oda yöneticisi ve Eczacı (Veri\Uyeler::rolTanimlari). Var olan role dokunulmaz; geri almada
 * kullanıcısı olmayan rol silinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            Uyeler::roller();
        }
    }

    public function down(): void
    {
        foreach (array_keys(Uyeler::rolTanimlari()) as $slug) {
            if (! DB::table('users')->where('role', $slug)->exists()) {
                DB::table('roles')->where('slug', $slug)->delete();
            }
        }
    }
};
