<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prefix kode booking per tenant.
 *
 * Format kode: `{PREFIX}-{TAHUN}-{URUT 5 digit}` — mis. `DCG-2026-00123`.
 * "DCG" adalah singkatan De'Corrinna, jadi ia milik KLIEN, bukan milik sistem.
 * Menaruhnya di sini membuat klien kedua tidak mewarisi prefix klien pertama.
 *
 * NULL = pakai `config('booking.default_prefix')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('booking_prefix', 8)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('booking_prefix');
        });
    }
};
