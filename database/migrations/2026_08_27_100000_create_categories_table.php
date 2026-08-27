<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori = "villa" di sisi publik (A2/A3). Bukti di frontend: tiga kartu
 * VillaListing identik dengan tiga baris CategoryList, dan breadcrumb ItemList
 * berbunyi "Master Data > Master Kategori > Villa De Corrinna".
 * Karena itu `slug` (dipakai /api/villas/{slug}) menempel di sini, bukan items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('icon')->nullable();     // nama ikon lucide, mis. 'house'
            $table->text('description')->nullable(); // "Tentang villa ini" (A3)
            $table->string('tagline')->nullable();   // teks pendek di kartu A2
            $table->string('location')->nullable();  // baris MapPin di A3
            $table->string('status')->default('Aktif'); // 'Aktif' | 'Nonaktif'
            $table->timestamps();
            $table->softDeletes();

            // Slug unik per tenant — dua klien boleh punya slug yang sama.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
