<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode akses kawasan & status benefit (M6, B6).
 *
 * Kolom di `bookings`, bukan tabel `booking_benefits` (dok 02 memberi dua
 * pilihan): satu booking = satu kode, dan daftar benefit (breakfast, welcome
 * drink) belum punya master data — belum ada yang perlu disimpan per baris.
 *
 * Status benefit diturunkan dari `benefit_shown_at`: NULL = "Belum Digunakan".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // NULL sampai booking dibayar (DP/Lunas). Unik per tenant; NULL
            // tidak saling bentrok di Postgres.
            $table->string('kode_akses_kawasan')->nullable()->after('kode_booking');
            $table->timestamp('benefit_shown_at')->nullable()->after('kode_akses_kawasan');

            $table->unique(['tenant_id', 'kode_akses_kawasan']);
        });

        // Booking yang sudah dibayar sebelum fitur ini ada ikut diberi kode,
        // supaya panel B6 tidak kosong untuk tamu yang sudah terjadwal.
        Booking::withoutGlobalScopes()
            ->whereIn('status', [Booking::STATUS_DP, Booking::STATUS_LUNAS, Booking::STATUS_SELESAI])
            ->whereNull('kode_akses_kawasan')
            ->each(fn (Booking $booking) => $booking->ensureAccessCode());
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'kode_akses_kawasan']);
            $table->dropColumn(['kode_akses_kawasan', 'benefit_shown_at']);
        });
    }
};
