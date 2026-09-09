<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zona waktu aplikasi — WIB, bukan UTC bawaan Laravel.
 *
 * Ini bukan sekadar soal tampilan. Operasional klien seluruhnya WIB, dan
 * booking bekerja dengan TANGGAL: nomor urut kode booking di-reset per tahun,
 * dan tarif dinilai weekday/weekend per malam. Dengan UTC, tujuh jam pertama
 * setiap hari WIB masih terhitung hari sebelumnya oleh server.
 *
 * Diuji karena `config/app.php` gampang tertimpa saat upgrade Laravel dan
 * kesalahannya senyap — tidak ada yang gagal, hanya tanggalnya meleset.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    public function test_application_runs_on_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        // Laravel memasang config ke PHP saat boot; pastikan benar-benar terpakai.
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
    }

    /**
     * Kasus paling tajam: 1 Januari 00:30 WIB masih 31 Desember di UTC.
     * Dengan UTC, booking pertama tahun baru akan memakai tahun lama pada
     * kodenya — dan menyambung nomor urut tahun sebelumnya, bukan mulai 00001.
     */
    public function test_booking_code_uses_wib_year_just_after_midnight(): void
    {
        // 2026-12-31 17:30 UTC == 2027-01-01 00:30 WIB.
        Carbon::setTestNow(Carbon::parse('2026-12-31 17:30:00', 'UTC'));

        $response = $this->postJson('/api/bookings', [
            'item_id' => Item::where('name', 'Kamar Superior')->firstOrFail()->id,
            'guest_name' => 'Rina Pratiwi',
            'guest_phone' => '081234567890',
            'check_in' => '2027-01-05',
            'check_out' => '2027-01-07',
            'pax' => 10,
        ]);

        $response->assertCreated();

        $booking = Booking::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('DCG-2027-', $booking->kode_booking);

        Carbon::setTestNow();
    }

    /**
     * `now()` mengikuti WIB, jadi stempel waktu yang ditulis ke DB adalah jam
     * dinding operasional — bukan tujuh jam di belakangnya.
     */
    public function test_timestamps_are_written_in_wib(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 01:00:00', 'UTC')); // 08:00 WIB

        $response = $this->postJson('/api/bookings', [
            'item_id' => Item::where('name', 'Kamar Superior')->firstOrFail()->id,
            'guest_name' => 'Budi Santoso',
            'guest_phone' => '081200001111',
            'check_in' => '2026-09-20',
            'check_out' => '2026-09-22',
            'pax' => 10,
        ]);

        $response->assertCreated();

        $booking = Booking::findOrFail($response->json('data.id'));
        $this->assertSame('2026-09-09 08:00:00', $booking->created_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }
}
