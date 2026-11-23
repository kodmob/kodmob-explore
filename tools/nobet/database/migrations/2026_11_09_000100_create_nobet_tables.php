<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nöbet: bölgeler, gruplar, eczaneler, özel günler, döngüler ve nöbetler. Her oda (alt site) kendi veritabanında.
 *
 * Nöbetin gün adı / ayı / mevsimi saklanmaz, tarihten hesaplanır (eski sistemde ayrı sütunlar tarihle uyuşmayabiliyordu).
 * Tarih DATE: saat ve saat dilimi yok (eski sistemde kayan tarihler elle düzeltiliyordu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nobet_bolgeler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 80);
            // Bölgelerin takvimdeki ve atamadaki sırası.
            $table->integer('sira')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('nobet_gruplar', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 80);
            // J kuralı: günün ilk nöbetçisi bu gruptansa sonraki bölgeler eş gruplardan (JSON kimlik listesi) ya da yedek gruptan.
            $table->text('eslesme')->nullable();
            $table->unsignedBigInteger('yedek_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('nobet_eczaneler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 120);
            $table->string('eczaci', 120)->nullable();
            $table->string('telefon', 30)->nullable();
            $table->string('adres', 300)->nullable();
            $table->decimal('enlem', 10, 7)->nullable();
            $table->decimal('boylam', 10, 7)->nullable();
            $table->unsignedBigInteger('bolge_id')->index();
            $table->unsignedBigInteger('grup_id')->nullable()->index();
            // Pasif eczane döngüye girmez; geçmiş nöbetleri ve raporu kalır.
            $table->boolean('aktif')->default(true);
            // Sisteme sonradan katılan eczanenin geçmiş nöbet sayıları (anahtar → sayı: pazartesi…, ocak…, kis…, haftaici…).
            $table->text('devir')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('nobet_ozel_gunler', function (Blueprint $table) {
            $table->id();
            $table->date('tarih')->unique();
            $table->string('ad', 120);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('nobet_donguler', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 120);
            $table->date('baslangic');
            $table->date('bitis');
            // Kural harfleri uygulama sırasıyla, ör. "A,N,B,C,D,E".
            $table->string('kurallar', 60);
            // İkili / tarih / grup engelleri, cumartesi gündüz sayıları (JSON).
            $table->text('ayarlar')->nullable();
            // Kuranın tohumu: aynı veriyle aynı takvim yeniden üretilebilir.
            $table->string('tohum', 24);
            $table->text('uyarilar')->nullable();
            $table->unsignedInteger('atama_sayisi')->default(0);
            $table->unsignedBigInteger('olusturan_id')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('nobet_nobetler', function (Blueprint $table) {
            $table->id();
            $table->date('tarih')->index();
            // tumgun | gunduz | gece
            $table->string('vardiya', 10)->default('tumgun');
            $table->unsignedBigInteger('bolge_id');
            $table->unsignedBigInteger('eczane_id');
            $table->unsignedBigInteger('grup_id')->nullable();
            // Özel gün (bayram, yılbaşı) nöbeti sayaçlara ve adalet raporuna girmez.
            $table->boolean('ozel_gun')->default(false);
            $table->unsignedBigInteger('dongu_id')->nullable()->index();
            // motor | elle | aktarim
            $table->string('kaynak', 10)->default('motor');
            // Motorun gerekçesi: hangi kural adayları kaçtan kaça indirdi, kaç aday arasından kura çekildi.
            $table->text('gerekce')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['eczane_id', 'tarih']);
        });
    }

    public function down(): void
    {
        foreach (['nobet_nobetler', 'nobet_donguler', 'nobet_ozel_gunler', 'nobet_eczaneler', 'nobet_gruplar', 'nobet_bolgeler'] as $tablo) {
            Schema::dropIfExists($tablo);
        }
    }
};
