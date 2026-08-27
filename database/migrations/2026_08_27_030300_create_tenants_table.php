<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel tenant (klien) — inti multi-tenancy De'Corrinna.
 * Satu baris = satu bisnis klien yang di-host Azatech.
 * Lihat docs/06-rbac-decorina.md §3 & §10.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();          // resolusi tenant publik (Fase 2)
            $table->string('domain')->nullable()->unique();
            $table->string('status')->default('Aktif'); // 'Aktif' | 'Nonaktif'
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
