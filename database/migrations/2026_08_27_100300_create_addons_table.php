<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-on (B4 — Master Add-on, dipakai A7). Kolom dari AddonMaster.tsx.
 *
 * `links` di form ("Link ke Item/Kategori") bisa menunjuk ke kategori ATAU
 * item, jadi pivotnya polimorfik (`addon_links`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('price')->default(0); // rupiah
            $table->text('description')->nullable();
            // Satuan tampilan di A7: 'unit' (Extra Bed), 'jam' (Late Checkout),
            // NULL = harga flat (Paket BBQ). Belum ada input di AddonMaster.tsx.
            $table->string('unit')->nullable();
            // Kebijakan deadline pemesanan, mis. catering H-4. Belum ada input di form.
            $table->unsignedSmallInteger('deadline_days')->nullable();
            $table->string('status')->default('Aktif');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('addon_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable'); // Category atau Item

            $table->unique(['addon_id', 'linkable_type', 'linkable_id'], 'addon_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_links');
        Schema::dropIfExists('addons');
    }
};
