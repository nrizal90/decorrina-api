<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey lokasi (A5, Fase 5) — kunjungan calon tamu ke lokasi sebelum menginap,
 * untuk item yang `requires_survey`.
 *
 * Slotnya TIDAK ditabelkan. Sesi (Pagi/Siang) dan aturan tanggalnya ada di
 * config/survey.php, dan baris di sini hanya muncul ketika sebuah slot benar-
 * benar DIPESAN. Jadi tabel ini adalah daftar janji temu, bukan kalender
 * kosong yang harus diisi lebih dulu.
 *
 * `booking_id` nullable karena urutan layarnya memang begitu: pengunjung
 * memilih jadwal survey (A5) SEBELUM mengisi data tamu (A6) dan membayar,
 * sehingga booking-nya belum ada saat slot dipilih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Survey menempel pada ITEM, bukan kategori: yang menentukan perlu
            // tidaknya survey adalah `items.requires_survey`.
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            $table->date('scheduled_date');
            // 'Pagi' | 'Siang' — kode sesi dari config/survey.php, bukan jam
            // mentah, supaya jam operasional bisa berubah tanpa migrasi data.
            $table->string('session');

            // 'Dijadwalkan' | 'Selesai' | 'Dibatalkan' — string, konsisten
            // dengan kolom status lain di proyek ini.
            $table->string('status')->default('Dijadwalkan');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Kuota per sesi dihitung di kode (config `capacity_per_session`),
            // jadi indeks ini untuk kecepatan hitungnya — bukan unique.
            $table->index(['tenant_id', 'scheduled_date', 'session']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
