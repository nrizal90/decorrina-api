<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Profil Tamu / CRM (B8) — baca saja.
 *
 * Yang dijaga: tamu lahir dari booking dan dikenali lewat nomor WhatsApp.
 * Nomor sama -> satu baris dengan booking bertambah; nomor baru -> baris baru.
 * Layar ini hanya memperlihatkan hasilnya.
 */
class GuestCrmTest extends TestCase
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

    /** Booking lewat jalur publik — cara tamu sungguhan masuk ke CRM. */
    private function bookPublicly(string $phone, string $name, int $daysAhead, array $extra = []): int
    {
        $checkIn = Carbon::today()->addDays($daysAhead);
        $item = Item::where('name', 'Kamar Superior')->firstOrFail();

        $response = $this->withHeader('X-Tenant', 'decorinna')->postJson('/api/public/bookings', array_merge([
            'item_id' => $item->id,
            'guest_name' => $name,
            'guest_phone' => $phone,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => 10,
        ], $extra));

        $response->assertCreated();

        return $response->json('data.id');
    }

    // --------------------------------------------------------------- dedup

    /** Inti kesepakatannya: nomor sama = tamu sama, booking-nya dihitung. */
    public function test_same_phone_is_counted_not_inserted_again(): void
    {
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 10);
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 20);
        $this->bookPublicly('081200000002', 'Budi Santoso', 30);

        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/guests');

        $response->assertOk()->assertJsonCount(2, 'data');

        $rina = collect($response->json('data'))->firstWhere('phone', '081200000001');
        $this->assertSame(2, $rina['bookings_count']);
        $this->assertSame(1, Guest::where('phone', '081200000001')->count());
    }

    /** Pengeluaran mengabaikan booking yang dibatalkan — uangnya tidak masuk. */
    public function test_total_spent_excludes_cancelled_bookings(): void
    {
        $first = $this->bookPublicly('081200000001', 'Rina Pratiwi', 10);
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 20);

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/bookings/{$first}/status", ['status' => Booking::STATUS_DIBATALKAN])->assertOk();

        $rina = collect($this->getJson('/api/guests')->json('data'))->firstWhere('phone', '081200000001');

        $expected = Booking::whereKeyNot($first)->sum('total');
        $this->assertSame(2, $rina['bookings_count']);
        $this->assertSame((int) $expected, $rina['total_spent']);
    }

    // -------------------------------------------------------------- daftar

    public function test_list_can_be_searched_and_filtered(): void
    {
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 10, ['guest_origin' => 'Jakarta', 'guest_type' => 'Pribadi']);
        $this->bookPublicly('081200000002', 'PT Nusantara Wisata', 20, ['guest_origin' => 'Surabaya', 'guest_type' => 'Instansi', 'guest_email' => 'events@nusantara.co.id']);

        Sanctum::actingAs($this->admin());

        $this->assertCount(1, $this->getJson('/api/guests?q=nusantara')->json('data'));
        $this->assertCount(1, $this->getJson('/api/guests?q=081200000001')->json('data'));
        $this->assertCount(1, $this->getJson('/api/guests?guest_type=Instansi')->json('data'));
        $this->assertCount(1, $this->getJson('/api/guests?origin=Jakarta')->json('data'));
        $this->assertCount(0, $this->getJson('/api/guests?origin=Bandung')->json('data'));
    }

    /** Dropdown asal daerah diisi dari data, bukan daftar kota tetap. */
    public function test_list_returns_the_origins_that_actually_exist(): void
    {
        $this->bookPublicly('081200000001', 'Rina', 10, ['guest_origin' => 'Jakarta']);
        $this->bookPublicly('081200000002', 'Budi', 20, ['guest_origin' => 'Bandung']);
        $this->bookPublicly('081200000003', 'Sari', 30);

        Sanctum::actingAs($this->admin());

        $this->assertSame(['Bandung', 'Jakarta'], $this->getJson('/api/guests')->json('origins'));
    }

    /** "Anonim" di layar = memesan tanpa akun. */
    public function test_account_link_is_exposed(): void
    {
        $this->bookPublicly('081200000001', 'Tamu Tanpa Akun', 10);

        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);
        $this->bookPublicly('081200000002', 'Tamu Ber-akun', 20);

        Sanctum::actingAs($this->admin());
        $rows = collect($this->getJson('/api/guests')->json('data'));

        $this->assertFalse($rows->firstWhere('phone', '081200000001')['has_account']);
        $this->assertTrue($rows->firstWhere('phone', '081200000002')['has_account']);
    }

    // -------------------------------------------------------------- detail

    public function test_detail_includes_booking_history_and_latest_group_size(): void
    {
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 10, ['pax' => 8, 'vehicle_count' => 1]);
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 20, ['pax' => 12, 'vehicle_count' => 3]);

        Sanctum::actingAs($this->admin());
        $guest = Guest::where('phone', '081200000001')->firstOrFail();

        $response = $this->getJson("/api/guests/{$guest->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.bookings')
            // Riwayat terbaru lebih dulu.
            ->assertJsonPath('data.bookings.0.pax', 12)
            ->assertJsonPath('data.bookings.0.villa', 'Villa De Corrinna')
            // Angka rombongan di profil = booking TERAKHIR.
            ->assertJsonPath('data.latest_pax', 12)
            ->assertJsonPath('data.latest_vehicle_count', 3);
    }

    // --------------------------------------------------------------- akses

    public function test_guest_of_another_tenant_is_not_visible(): void
    {
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 10);
        $guest = Guest::firstOrFail();

        // Admin klien LAIN — bukan header X-Tenant, yang memang diabaikan
        // untuk user internal (keputusan Fase 1).
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        $otherAdmin = User::factory()->create(['tenant_id' => $other->id]);
        $otherAdmin->assignRole('admin');
        Sanctum::actingAs($otherAdmin);

        $this->getJson('/api/guests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/guests/{$guest->id}")->assertStatus(404);
    }

    public function test_customer_cannot_browse_the_crm(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/guests')->assertStatus(403);
    }

    public function test_stakeholder_can_read_the_crm(): void
    {
        $this->bookPublicly('081200000001', 'Rina Pratiwi', 10);

        $stakeholder = User::factory()->create(['tenant_id' => $this->admin()->tenant_id]);
        $stakeholder->assignRole('stakeholder');
        Sanctum::actingAs($stakeholder);

        $this->getJson('/api/guests')->assertOk()->assertJsonCount(1, 'data');
    }
}
