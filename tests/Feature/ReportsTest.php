<?php

namespace Tests\Feature;

use App\Models\Addon;
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
 * Laporan & Analitik (B10, Fase 8).
 *
 * Dua keputusan klien yang dijaga di sini:
 *  - okupansi = booking yang menghalangi kalender (semua kecuali Dibatalkan);
 *  - pendapatan = hanya DP Dibayar / Lunas / Selesai.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

        // Jendela tetap 10 malam, jauh dari hari ini supaya tidak bergantung jam.
        $this->from = Carbon::today()->addDays(30);
        $this->to = $this->from->copy()->addDays(10);
    }

    private function item(string $name = 'Kamar Superior'): Item
    {
        return Item::where('name', $name)->firstOrFail();
    }

    private function book(Item $item, int $offset, int $nights, ?string $status = null, array $extra = []): Booking
    {
        $checkIn = $this->from->copy()->addDays($offset);

        $response = $this->postJson('/api/bookings', array_merge([
            'item_id' => $item->id,
            'guest_name' => 'Tamu '.$offset,
            'guest_phone' => '0812'.str_pad((string) $offset, 6, '0', STR_PAD_LEFT).$item->id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays($nights)->toDateString(),
            'pax' => $item->cap_min,
        ], $extra));

        $response->assertCreated();
        $booking = Booking::findOrFail($response->json('data.id'));

        if ($status !== null) {
            $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => $status])->assertOk();
            $booking->refresh();
        }

        return $booking;
    }

    private function report(): array
    {
        return $this->getJson('/api/reports?'.http_build_query([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ]))->assertOk()->json('data');
    }

    private function rowFor(array $report, Item $item): array
    {
        return collect($report['occupancy']['rows'])->firstWhere('item_id', $item->id);
    }

    // ----------------------------------------------------------- okupansi

    public function test_occupancy_counts_nights_inside_the_window(): void
    {
        $item = $this->item();
        $this->book($item, 2, 3); // malam +2, +3, +4

        $row = $this->rowFor($this->report(), $item);

        $this->assertSame(3, $row['filled_nights']);
        $this->assertSame(10, $row['available_nights']);
        $this->assertSame(30, $row['pct']);
    }

    /** Booking yang melewati batas jendela dipotong, bukan dihitung penuh. */
    public function test_occupancy_clips_bookings_that_cross_the_window(): void
    {
        $item = $this->item();
        $this->book($item, 8, 5); // malam +8..+12 -> hanya +8, +9 di jendela

        $this->assertSame(2, $this->rowFor($this->report(), $item)['filled_nights']);
    }

    /** Menunggu pembayaran TETAP terisi — kamarnya tidak bisa dijual ke orang lain. */
    public function test_pending_bookings_count_as_occupied(): void
    {
        $item = $this->item();
        $this->book($item, 0, 2); // status default: Menunggu Pembayaran

        $this->assertSame(2, $this->rowFor($this->report(), $item)['filled_nights']);
    }

    public function test_cancelled_bookings_free_the_nights(): void
    {
        $item = $this->item();
        $this->book($item, 0, 2, Booking::STATUS_DIBATALKAN);

        $this->assertSame(0, $this->rowFor($this->report(), $item)['filled_nights']);
    }

    public function test_trend_has_one_point_per_night(): void
    {
        $item = $this->item();
        $this->book($item, 1, 1);

        $trend = $this->report()['occupancy']['trend'];

        $this->assertCount(10, $trend);
        $this->assertSame(1, $trend[1]['occupied']);
        $this->assertSame(0, $trend[0]['occupied']);
    }

    // --------------------------------------------------------- pendapatan

    /** Hanya booking yang uangnya (setidaknya sebagian) sudah masuk. */
    public function test_revenue_only_counts_paid_statuses(): void
    {
        $item = $this->item();
        $pending = $this->book($item, 0, 2);
        $paid = $this->book($item, 3, 2, Booking::STATUS_LUNAS);
        $this->book($item, 6, 2, Booking::STATUS_DIBATALKAN);

        $rows = collect($this->report()['revenue']['rows'])->keyBy('category');

        $this->assertSame(1, $rows['Booking Villa']['bookings']);
        $this->assertSame($paid->subtotal_item, $rows['Booking Villa']['total']);
        $this->assertNotSame($pending->subtotal_item + $paid->subtotal_item, $rows['Booking Villa']['total']);
    }

    public function test_revenue_splits_villa_and_addons(): void
    {
        $item = $this->item();
        // Add-on seeder tertaut ke KATEGORI, bukan langsung ke item.
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();

        $booking = $this->book($item, 0, 2, Booking::STATUS_LUNAS, [
            'addons' => [['addon_id' => $addon->id, 'qty' => 2]],
        ]);

        $rows = collect($this->report()['revenue']['rows'])->keyBy('category');

        $this->assertSame($booking->subtotal_item, $rows['Booking Villa']['total']);
        $this->assertSame($booking->subtotal_addons, $rows['Add-on']['total']);
        $this->assertSame(1, $rows['Add-on']['bookings']);
        $this->assertSame(100, $rows['Booking Villa']['pct'] + $rows['Add-on']['pct']);
    }

    /** Keputusan klien: baris Catering tampil 0, bukan disembunyikan. */
    public function test_catering_row_is_present_but_zero(): void
    {
        $rows = collect($this->report()['revenue']['rows'])->keyBy('category');

        $this->assertSame(0, $rows['Catering']['total']);
        $this->assertFalse($rows['Catering']['available']);
    }

    // ---------------------------------------------------------------- crm

    public function test_crm_origin_share_uses_guests_with_an_origin_as_basis(): void
    {
        $item = $this->item();
        $this->book($item, 0, 1, null, ['guest_origin' => 'Jakarta', 'guest_type' => 'Pribadi']);
        $this->book($item, 2, 1, null, ['guest_origin' => 'Jakarta', 'guest_type' => 'Instansi']);
        $this->book($item, 4, 1, null, ['guest_origin' => 'Bandung']);
        $this->book($item, 6, 1); // tanpa asal daerah — tidak ikut jadi penyebut

        $crm = $this->report()['crm'];

        $this->assertSame(3, $crm['origins_basis']);
        $this->assertSame('Jakarta', $crm['origins'][0]['origin']);
        $this->assertSame(67, $crm['origins'][0]['pct']);
        $this->assertSame(50, $crm['guest_type']['pribadi_pct']);
    }

    // -------------------------------------------------------------- akses

    public function test_window_defaults_to_the_last_30_days(): void
    {
        $data = $this->getJson('/api/reports')->assertOk()->json('data');

        $this->assertSame(Carbon::tomorrow()->toDateString(), $data['to']);
        $this->assertCount(30, $data['occupancy']['trend']);
    }

    public function test_window_longer_than_a_year_is_rejected(): void
    {
        $this->getJson('/api/reports?from=2026-01-01&to=2027-06-01')->assertStatus(422);
    }

    public function test_stakeholder_can_view_reports(): void
    {
        $stakeholder = User::factory()->create(['tenant_id' => User::where('email', 'admin@decorinna.test')->firstOrFail()->tenant_id]);
        $stakeholder->assignRole('stakeholder');
        Sanctum::actingAs($stakeholder);

        $this->getJson('/api/reports')->assertOk();
    }

    public function test_customer_cannot_view_reports(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/reports')->assertStatus(403);
    }
}
