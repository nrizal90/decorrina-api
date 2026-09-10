<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey lokasi (A5 & B4, Fase 5) — kunjungan calon tamu ke lokasi sebelum
 * memutuskan menginap.
 *
 * Tabel ini melayani DUA jalur yang bentuknya berbeda, dan itu yang menjelaskan
 * banyaknya kolom nullable di sini:
 *
 *  1. Customer (A5) — memilih slot Pagi/Siang saat memesan. Terikat item dan
 *     booking, tapi booking-nya baru ada setelah pembayaran.
 *  2. Admin (B4) — menjadwalkan sendiri untuk calon tamu yang menghubungi
 *     lewat WhatsApp. Belum tentu ada booking, item, atau tamu terdaftar;
 *     jamnya bebas, tidak harus jatuh di sesi baku.
 *
 * Slotnya sendiri TIDAK ditabelkan: sesi dan aturan tanggal ada di
 * config/survey.php, dan baris di sini hanya muncul ketika sebuah jadwal
 * benar-benar dipesan. Lihat App\Support\SurveySlots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Villa yang disurvei — satu-satunya kolom tujuan yang WAJIB.
            // Admin menjadwalkan per villa; kamar spesifik sering belum
            // ditentukan saat survey justru dipakai untuk memilihnya.
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // Tamu terdaftar bila ada; kalau belum, cukup nama/kontak apa
            // adanya seperti yang diketik admin di modal "Jadwalkan Survey".
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name');

            // Rencana check-in — dasar aturan H-7. Tanpa booking, tanggal ini
            // satu-satunya acuan; dengan booking, disalin dari check_in-nya.
            $table->date('planned_check_in')->nullable();

            $table->date('scheduled_date');
            $table->time('scheduled_time');
            // Kode sesi dari config/survey.php ('Pagi'/'Siang'), diturunkan
            // dari jam. NULL untuk jadwal di luar jam sesi baku — jadwal
            // seperti itu tidak ikut memakan kuota slot customer.
            $table->string('session')->nullable();

            // Penanggung jawab room tour. Menunjuk user internal, bukan nama
            // lepas, supaya riwayat tetap utuh saat orangnya nonaktif.
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();

            // 'Terjadwal' | 'Menunggu Laporan' | 'Selesai' | 'Dibatalkan'
            $table->string('status')->default('Terjadwal');
            $table->text('report')->nullable();
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
