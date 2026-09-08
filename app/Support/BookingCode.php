<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Generator `kode_booking` — format `{PREFIX}-{TAHUN}-{URUT 5 digit}`,
 * mis. `DCG-2026-00123`.
 *
 * Nomor urut di-reset tiap tahun dan dihitung PER TENANT, jadi klien kedua
 * memulai dari 00001-nya sendiri.
 *
 * Soal balapan: dua booking yang dibuat bersamaan bisa menghitung nomor urut
 * yang sama. Karena itu pemanggilnya WAJIB berada di dalam transaksi, dan
 * tabel `bookings` punya unique(['tenant_id','kode_booking']) sebagai jaring
 * pengaman terakhir — kode ini mengunci baris tenant agar nomor urutnya
 * berurutan, bukan sekadar berharap tidak bertabrakan.
 */
class BookingCode
{
    public static function generate(int $tenantId): string
    {
        $year = now()->year;

        // Kunci baris tenant sampai transaksi selesai: pemanggil kedua menunggu,
        // sehingga tidak ada dua booking yang membaca nomor urut terakhir sama.
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        $prefix = Tenant::withoutGlobalScopes()->whereKey($tenantId)->value('booking_prefix')
            ?: config('booking.default_prefix');

        $pattern = sprintf('%s-%d-%%', $prefix, $year);

        $lastSequence = Booking::withoutGlobalScopes()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('kode_booking', 'like', $pattern)
            // Urut berdasar potongan nomor, bukan string penuh — supaya tetap
            // benar bila padding pernah berubah.
            ->selectRaw('MAX(CAST(SPLIT_PART(kode_booking, \'-\', 3) AS INTEGER)) AS seq')
            ->value('seq');

        $next = ((int) $lastSequence) + 1;

        return sprintf(
            '%s-%d-%s',
            $prefix,
            $year,
            str_pad((string) $next, config('booking.sequence_padding'), '0', STR_PAD_LEFT),
        );
    }
}
