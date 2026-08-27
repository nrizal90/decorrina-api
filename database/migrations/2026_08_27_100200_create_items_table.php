<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item = kamar/paket di dalam sebuah kategori (villa). Kolom diturunkan dari
 * ItemForm.tsx (tab General / Pricing / Configuration) dan ItemList.tsx.
 *
 * Uang disimpan sebagai integer rupiah (bigint), tidak pernah float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            // Tab General
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('capacity_type')->default('Rentang'); // 'Tetap' | 'Rentang'
            $table->unsignedInteger('cap_min')->default(0);
            $table->unsignedInteger('cap_max')->default(0);

            // Tab Pricing (rupiah)
            $table->unsignedBigInteger('price_weekday')->default(0);
            $table->unsignedBigInteger('price_weekend')->default(0);

            // Tab Configuration
            // 'Full Payment' | 'DP + Pelunasan' | 'Keduanya — customer memilih'
            // ⚠ Kebijakan default per item BELUM final (KAK 12.5 #7, dok 05) —
            // backend hanya menyimpan pilihan admin, tidak menetapkan default.
            $table->string('payment_mode')->default('Full Payment');
            $table->unsignedBigInteger('dp_minimum')->nullable();
            $table->boolean('requires_survey')->default(false);

            $table->string('status')->default('Nonaktif'); // "Simpan Draft" = Nonaktif
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        // Foto item — kolom disiapkan sekarang, endpoint upload menyusul.
        Schema::create('item_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_photos');
        Schema::dropIfExists('items');
    }
};
