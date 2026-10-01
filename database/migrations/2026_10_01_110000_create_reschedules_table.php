<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reschedule booking (A13 / B3). Satu baris = satu pengajuan tamu ATAU satu
 * reschedule langsung oleh admin — sekaligus riwayat perpindahan tanggal.
 *
 * Nilai lama/baru disimpan, bukan hanya yang baru: booking ditimpa saat
 * disetujui, dan tanpa baris ini jejak tanggal & harga semula hilang.
 *
 * `difference` = total baru − total lama. Untuk booking yang sudah Lunas,
 * selisih positif adalah tagihan sampai `difference_paid_at` diisi admin.
 * Booking DP tidak perlu: pelunasannya dihitung dari total baru (Ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // 'customer' = pengajuan dari Riwayat, 'admin' = langsung dari B3.
            $table->string('source');
            // 'Menunggu Persetujuan' | 'Disetujui' | 'Ditolak'
            $table->string('status');

            $table->foreignId('old_item_id')->constrained('items')->restrictOnDelete();
            $table->date('old_check_in');
            $table->date('old_check_out');
            $table->unsignedBigInteger('old_total');

            $table->foreignId('new_item_id')->constrained('items')->restrictOnDelete();
            $table->date('new_check_in');
            $table->date('new_check_out');
            // Dihitung ulang saat disetujui — harga bisa berubah sejak diajukan.
            $table->unsignedBigInteger('new_total');
            $table->bigInteger('difference');
            $table->timestamp('difference_paid_at')->nullable();

            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reschedules');
    }
};
