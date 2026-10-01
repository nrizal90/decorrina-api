<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HolidaySeason;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Harga liburan: CRUD holiday season + malam libur di BookingPricing.
 * Kamar Superior = 4jt weekday / 4,8jt weekend.
 */
class HolidaySeasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function item(): Item
    {
        return Item::where('name', 'Kamar Superior')->firstOrFail();
    }

    private function holiday(string $name, string $date): void
    {
        HolidaySeason::forceCreate([
            'tenant_id' => $this->item()->tenant_id, 'name' => $name, 'start_date' => $date, 'end_date' => $date,
        ]);
    }

    private function book(string $in, string $out): Booking
    {
        $id = $this->postJson('/api/bookings', [
            'item_id' => $this->item()->id,
            'guest_name' => 'Rina',
            'guest_phone' => '081234567890',
            'check_in' => $in,
            'check_out' => $out,
            'pax' => 10,
        ])->assertCreated()->json('data.id');

        return Booking::findOrFail($id);
    }

    public function test_admin_can_crud_holiday_seasons(): void
    {
        $id = $this->postJson('/api/admin/holiday-seasons', [
            'name' => 'Lebaran',
            'start_date' => '2027-03-20',
            'end_date' => '2027-03-24',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/admin/holiday-seasons?year=2027')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Lebaran')
            ->assertJsonPath('data.0.end_date', '2027-03-24');

        $this->putJson("/api/admin/holiday-seasons/{$id}", [
            'name' => 'Idul Fitri',
            'start_date' => '2027-03-20',
            'end_date' => '2027-03-21',
        ])->assertOk()->assertJsonPath('data.name', 'Idul Fitri');

        $this->deleteJson("/api/admin/holiday-seasons/{$id}")->assertNoContent();
        $this->assertDatabaseCount('holiday_seasons', 0);
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->postJson('/api/admin/holiday-seasons', [
            'name' => 'Salah',
            'start_date' => '2027-03-20',
            'end_date' => '2027-03-19',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_holiday_night_uses_holiday_price_over_weekday_and_weekend(): void
    {
        $this->item()->update(['price_holiday' => 6_000_000]);
        // Senin 17 Agustus 2026 & Sabtu 22 Agustus 2026.
        $this->holiday('HUT RI', '2026-08-17');
        $this->holiday('Uji', '2026-08-22');

        // 16 (Minggu, weekend) + 17 (libur) + 18 (weekday).
        $booking = $this->book('2026-08-16', '2026-08-19');
        $this->assertSame(4_800_000 + 6_000_000 + 4_000_000, $booking->subtotal_item);
        $this->assertSame(6_000_000, $booking->price_holiday);

        // Libur jatuh di Sabtu tetap tarif libur.
        $quote = $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/villas/villa-de-corrinna/quote?item_id='.$this->item()->id.'&check_in=2026-08-22&check_out=2026-08-23')
            ->assertOk();
        $quote->assertJsonPath('data.holiday_nights', 1)->assertJsonPath('data.subtotal', 6_000_000);
    }

    public function test_item_without_holiday_price_falls_back_to_weekend_price(): void
    {
        $this->holiday('HUT RI', '2026-08-17');

        $booking = $this->book('2026-08-17', '2026-08-18');

        $this->assertSame(4_800_000, $booking->subtotal_item);
        $this->assertNull($booking->price_holiday);
    }

    public function test_other_tenants_holidays_do_not_apply(): void
    {
        $other = Tenant::create(['name' => 'Lain', 'slug' => 'lain', 'status' => 'Aktif']);
        HolidaySeason::forceCreate([
            'tenant_id' => $other->id, 'name' => 'Libur lain', 'start_date' => '2026-08-17', 'end_date' => '2026-08-17',
        ]);

        $booking = $this->book('2026-08-17', '2026-08-18');

        $this->assertSame(4_000_000, $booking->subtotal_item);
    }
}
