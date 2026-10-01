<?php

namespace Database\Seeders;

use App\Models\HolidaySeason;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Libur nasional & cuti bersama Mei 2026 – Jan 2027 (daftar dari klien).
 * Terpisah dari MasterDataSeeder supaya test harga tidak ikut terpengaruh.
 *
 * Idempoten — aman dijalankan berulang.
 */
class HolidaySeasonSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'decorinna')->first();

        if (! $tenant) {
            return;
        }

        $rows = [
            ['2026-05-01', 'Hari Buruh'],
            ['2026-05-14', 'Kenaikan Isa Almasih'],
            ['2026-05-15', 'Cuti bersama'],
            ['2026-05-27', 'Idul Adha'],
            ['2026-05-31', 'Waisak'],
            ['2026-06-01', 'Hari Lahir Pancasila'],
            ['2026-06-16', 'Tahun Baru Islam'],
            ['2026-08-17', 'HUT RI'],
            ['2026-08-25', 'Maulid Nabi'],
            ['2026-12-24', 'Cuti bersama Natal'],
            ['2026-12-25', 'Natal'],
            ['2027-01-01', 'Tahun Baru'],
        ];

        foreach ($rows as [$date, $name]) {
            HolidaySeason::withoutGlobalScope('tenant')->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['start_date' => $date, 'end_date' => $date],
            );
        }
    }
}
