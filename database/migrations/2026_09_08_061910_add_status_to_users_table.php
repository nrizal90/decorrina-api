<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status aktif/nonaktif akun (B13 — kolom STATUS di UserRoleManagement).
 *
 * Nonaktif = akun ditahan, BUKAN dihapus: user kehilangan akses login tapi
 * jejaknya di booking/ledger tetap utuh. Karena itu dipakai kolom status,
 * bukan soft delete.
 *
 * Nilai memakai string Indonesia persis seperti label di FE ('Aktif' /
 * 'Nonaktif'), konsisten dengan `status` di categories/items/facilities/addons
 * — lihat terminologi terkunci di corrinna-scaffold/CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Default 'Aktif' agar seluruh user lama otomatis tetap bisa login.
            $table->string('status')->default('Aktif')->after('password');

            // Login memfilter kolom ini di setiap percobaan masuk.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
