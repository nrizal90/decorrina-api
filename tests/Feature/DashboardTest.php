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
 * Dashboard admin (B1, Fase 8).
 *
 * Yang dijaga: KPI dihitung dari data nyata beserta pembandingnya, kartu
 * pendapatan disembunyikan dari role tanpa akses buku kas, dan aktivitas
 * terbaru diturunkan dari kejadian yang memang tercatat.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    private function book(int $daysAhead = 20): Booking
    {
        $item = Item::where('name', 'Kamar Superior')->firstOrFail();
        $checkIn = Carbon::today()->addDays($daysAhead);

        $response = $this->postJson('/api/bookings', [
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '0812'.random_int(100000, 999999),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => $item->cap_min,
        ]);

        $response->assertCreated();

        return Booking::findOrFail($response->json('data.id'));
    }

    public function test_kpis_reflect_real_data(): void
    {
        Sanctum::actingAs($this->admin());

        $a = $this->book(20);
        $b = $this->book(30);
        $this->patchJson("/api/bookings/{$b->id}/status", ['status' => Booking::STATUS_LUNAS])->assertOk();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(2, $data['bookings_today']['value']);
        $this->assertSame(0, $data['bookings_today']['previous']);
        // Hanya $a yang masih menunggu.
        $this->assertSame(1, $data['pending']['bookings']);
        // Pelunasan $b tercatat di buku kas bulan ini.
        $this->assertSame($b->total, $data['revenue']['value']);
        $this->assertCount(30, $data['trend']);
        $this->assertSame(2, end($data['trend'])['count']);
    }

    /** Staff punya reports:view tapi bukan ledger:* — kartu pendapatan kosong. */
    public function test_revenue_is_hidden_from_staff(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->admin()->tenant_id]);
        $staff->assignRole('staff');
        Sanctum::actingAs($staff);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertNull($data['revenue']);
        $this->assertArrayHasKey('bookings_today', $data);
    }

    public function test_activities_are_newest_first_and_derived_from_events(): void
    {
        Sanctum::actingAs($this->admin());

        $booking = $this->book();
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])->assertOk();

        $activities = $this->getJson('/api/dashboard')->json('data.activities');
        $types = array_column($activities, 'type');

        $this->assertContains('booking_created', $types);
        $this->assertContains('payment_received', $types);

        $times = array_column($activities, 'at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_customer_cannot_view_dashboard(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/dashboard')->assertStatus(403);
    }
}
