<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fasilitas (B4 — Master Fasilitas) + pivot ke KATEGORI.
 *
 * Catatan revisi: dok 02 semula menulis pivot `facility_item`, tetapi di
 * frontend fasilitas tampil di halaman villa (A3 = kategori) dan ItemForm sama
 * sekali tidak punya pemilih fasilitas. Jadi relasinya category <-> facility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->nullable(); // nama ikon lucide, mis. 'waves'
            $table->string('status')->default('Aktif');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('category_facility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();

            $table->unique(['category_id', 'facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_facility');
        Schema::dropIfExists('facilities');
    }
};
