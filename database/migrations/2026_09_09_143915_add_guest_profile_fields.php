<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melengkapi data tamu sesuai form A6 (CustomerDataForm.tsx), yang sejak awal
 * meminta lebih banyak dari yang bisa disimpan tabel `guests`.
 *
 * Pembagiannya menuruti apa yang melekat pada SIAPA vs pada SATU KALI MENGINAP:
 *  - `guests`   : tanggal lahir, asal daerah, pribadi/instansi — sifat orangnya,
 *                 sama di semua booking, jadi tidak diulang tiap kali.
 *  - `bookings` : jumlah kendaraan — beda tiap kunjungan (rombongan yang sama
 *                 bisa datang dengan 2 mobil sekarang dan 1 mobil bulan depan).
 *
 * Semua nullable: booking manual admin (B3) hanya mengisi nama & telepon, dan
 * tamu walk-in yang dicatat susulan sering tidak punya data selengkap ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('email');
            $table->string('origin')->nullable()->after('birth_date');
            // 'Pribadi' | 'Instansi' — string, bukan enum DB, konsisten dengan
            // kolom status lain di proyek ini (lihat categories/items).
            $table->string('guest_type')->nullable()->after('origin');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('vehicle_count')->nullable()->after('pax');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'origin', 'guest_type']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('vehicle_count');
        });
    }
};
