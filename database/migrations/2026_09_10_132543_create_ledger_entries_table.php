<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku kas (B9, Fase 7) — satu baris per uang masuk atau keluar.
 *
 * Dua sumber baris:
 *  1. OTOMATIS dari booking: saat admin menandai DP Dibayar / Lunas di papan
 *     B3, pemasukannya dicatat di sini (`booking_id` terisi). Ini satu-satunya
 *     cara pendapatan booking sampai ke buku kas — tidak ada yang mengetiknya
 *     ulang.
 *  2. MANUAL lewat "Catat Transaksi": pengeluaran operasional dan pemasukan
 *     lain yang tidak berasal dari booking (`booking_id` NULL).
 *
 * `category` string bebas, bukan enum: daftar saran ada di config/finance.php,
 * dan admin boleh menulis kategori baru tanpa migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->date('entry_date');
            $table->string('description');
            // 'Pemasukan' | 'Pengeluaran'
            $table->string('type');
            $table->string('category');
            // Integer rupiah, selalu positif; arahnya ditentukan `type`.
            $table->unsignedBigInteger('amount');

            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            // Siapa yang mencatat; NULL untuk baris otomatis dari sistem.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entry_date']);
            $table->index(['tenant_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
