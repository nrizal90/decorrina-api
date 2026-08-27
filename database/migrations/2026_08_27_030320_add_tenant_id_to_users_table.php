<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan user ke tenant. NULLABLE karena:
 *  - `superadmin-azatech` (vendor) → tenant_id NULL (lintas semua tenant).
 *  - `customer` (akun global lintas klien) → tenant_id NULL (pakai ownership).
 * User internal (superadmin-klien/admin/staff/stakeholder) → tenant_id terisi.
 * Lihat docs/06-rbac-decorina.md §3.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
