<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamu (CRM). Dibuat otomatis saat booking pertama seorang tamu — booking
 * anonim sekalipun tetap menghasilkan baris di sini, karena villa perlu tahu
 * siapa yang menginap.
 *
 * Berbeda dari `users`: `guests` adalah data ORANG milik klien (per tenant),
 * sedangkan `users` adalah AKUN untuk login. Customer yang punya akun akan
 * punya keduanya, terhubung lewat `user_id`.
 *
 * Layar CRM penuh menyusul di Fase 7; tabel ini sengaja dibuat seminimal yang
 * dibutuhkan booking agar tidak mendahului desain yang belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Terisi bila tamu memesan lewat akun; NULL untuk booking anonim
            // atau booking manual yang dibuat admin.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Nomor WhatsApp jadi kunci alami penggabungan tamu berulang, tapi
            // hanya di dalam satu tenant — dua klien boleh punya tamu bernomor
            // sama tanpa saling mengganggu.
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
