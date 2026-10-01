<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga liburan (holiday season).
 *
 * Satu baris = satu periode libur (Lebaran bisa beberapa hari, Natal satu
 * hari). Malam menginap yang jatuh di dalam periode ini ditagih
 * `items.price_holiday` — NULL berarti item tidak punya tarif khusus dan
 * jatuh ke tarif weekend.
 *
 * `bookings.price_holiday` adalah snapshot seperti kolom harga lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index(['tenant_id', 'start_date', 'end_date']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('price_holiday')->nullable()->after('price_weekend');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('price_holiday')->nullable()->after('price_weekend');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('price_holiday'));
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('price_holiday'));
        Schema::dropIfExists('holiday_seasons');
    }
};
