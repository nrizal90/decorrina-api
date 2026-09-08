<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking (Fase 3) — inti alur pemesanan.
 *
 * Keputusan 2026-09-08: booking menunjuk ke ITEM (kamar/paket), bukan ke
 * kategori. Harga, kapasitas, dan mode pembayaran semuanya milik item — itu
 * yang ditetapkan di Fase 2. Kategori diturunkan lewat relasi item, jadi tidak
 * perlu disimpan ulang di sini.
 *
 * Harga disimpan sebagai SNAPSHOT (`price_weekday`..`total`). Booking adalah
 * catatan kesepakatan pada satu titik waktu: mengubah harga item besok tidak
 * boleh mengubah nilai booking yang sudah terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: item yang sudah pernah dipesan tidak boleh
            // lenyap begitu saja — riwayatnya harus tetap bisa dibaca.
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();

            // Akun customer, bila memesan sambil login. NULL untuk booking
            // anonim dan booking manual admin.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kode_booking');

            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('pax');

            // Snapshot harga saat booking dibuat.
            $table->unsignedBigInteger('price_weekday');
            $table->unsignedBigInteger('price_weekend');
            $table->unsignedBigInteger('subtotal_item');
            $table->unsignedBigInteger('subtotal_addons')->default(0);
            $table->unsignedBigInteger('total');

            // Snapshot kebijakan pembayaran item (keputusan 2026-09-08: mode
            // mengikuti pengaturan item, tidak dipilih bebas oleh admin).
            $table->string('payment_mode');
            $table->unsignedBigInteger('dp_minimum')->nullable();

            $table->string('status')->default('Menunggu Pembayaran');

            // 'customer' = alur publik A4-A10, 'admin' = booking manual B3.
            $table->string('source')->default('customer');

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Kode unik PER TENANT: klien kedua boleh memulai penomorannya
            // sendiri tanpa bertabrakan.
            $table->unique(['tenant_id', 'kode_booking']);

            // Dua pola query terpanas: papan booking admin (per status) dan
            // pengecekan ketersediaan tanggal per item.
            $table->index(['tenant_id', 'status']);
            $table->index(['item_id', 'check_in', 'check_out']);
        });

        Schema::create('booking_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('qty')->default(1);
            // Snapshot juga — harga add-on bisa berubah setelah booking dibuat.
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();

            $table->unique(['booking_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_addons');
        Schema::dropIfExists('bookings');
    }
};
