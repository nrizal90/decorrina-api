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
 * Riwayat Transaksi (A13): hanya booking yang dibuat sambil login, milik
 * pemanggil sendiri. Booking anonim tidak diklaim lewat nomor HP.
 */
class MyBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function customer(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('customer');

        return $user;
    }

    private function book(int $offsetDays, string $phone = '081234567890'): void
    {
        $checkIn = Carbon::today()->addDays($offsetDays);

        $this->withHeader('X-Tenant', 'decorinna')->postJson('/api/public/bookings', [
            'item_id' => Item::where('name', 'Kamar Superior')->firstOrFail()->id,
            'guest_name' => 'Rina Pratiwi',
            'guest_phone' => $phone,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => 10,
        ])->assertCreated();
    }

    public function test_logged_in_booking_is_linked_and_listed_for_its_owner_only(): void
    {
        $rina = $this->customer();
        $other = $this->customer();

        // Anonim dulu, dengan nomor HP yang sama — tidak boleh ikut terklaim.
        $this->book(20);

        // Token sungguhan seperti dari browser, bukan Sanctum::actingAs — rute
        // publik membaca user lewat auth('sanctum') tanpa middleware auth.
        $this->withToken($rina->createToken('test')->plainTextToken);
        $this->book(30);
        $this->withoutToken();
        Sanctum::actingAs($rina);

        $this->assertSame($rina->id, Booking::latest('id')->first()->user_id);

        $this->getJson('/api/me/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.villa', 'Villa De Corrinna')
            ->assertJsonPath('data.0.check_in', Carbon::today()->addDays(30)->toDateString());

        Sanctum::actingAs($other);
        $this->getJson('/api/me/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_booking_is_not_linked_to_the_admin(): void
    {
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

        $this->postJson('/api/bookings', [
            'item_id' => Item::where('name', 'Kamar Superior')->firstOrFail()->id,
            'guest_name' => 'Walk-in',
            'guest_phone' => '081200000077',
            'check_in' => Carbon::today()->addDays(40)->toDateString(),
            'check_out' => Carbon::today()->addDays(41)->toDateString(),
            'pax' => 10,
        ])->assertCreated();

        $this->assertNull(Booking::firstOrFail()->user_id);
    }

    public function test_history_requires_login(): void
    {
        $this->getJson('/api/me/bookings')->assertUnauthorized();
    }
}
