<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Item;
use App\Models\Survey;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Booking yang dibuat sendiri pengunjung (A6–A10) lewat `POST /api/public/bookings`.
 *
 * Keputusan yang dijaga di sini: booking SELALU lahir "Menunggu Pembayaran",
 * lalu admin yang menggerakkannya dari papan B3. Itu yang membuat alur ini
 * bisa dipakai sebelum gateway pembayaran ada — dan tetap berguna sesudahnya
 * sebagai jalan keluar saat gateway bermasalah atau tamu transfer manual.
 */
class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function item(string $name = 'Kamar Superior'): Item
    {
        return Item::where('name', $name)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $checkIn = Carbon::today()->addDays(20);

        return array_merge([
            'item_id' => $this->item()->id,
            'guest_name' => 'Rina Pratiwi',
            'guest_phone' => '081234567890',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => 10,
        ], $overrides);
    }

    private function book(array $overrides = [])
    {
        return $this->withHeader('X-Tenant', 'decorinna')
            ->postJson('/api/public/bookings', $this->payload($overrides));
    }

    // ------------------------------------------------------------ dasar

    public function test_visitor_can_book_without_an_account(): void
    {
        $response = $this->book();

        $response->assertCreated()
            ->assertJsonPath('data.status', Booking::STATUS_MENUNGGU)
            ->assertJsonPath('data.source', 'customer');

        $this->assertMatchesRegularExpression('/^DCG-\d{4}-\d{5}$/', $response->json('data.kode_booking'));

        // Anonim: tamunya tercatat, tapi tidak tertaut akun mana pun.
        $this->assertNull(Guest::firstOrFail()->user_id);
    }

    /**
     * Inti kesepakatannya: admin yang menandai pembayaran selama gateway belum
     * ada. Booking harus sampai di papan B3 dalam keadaan bisa digerakkan.
     */
    public function test_admin_can_settle_a_public_booking_manually(): void
    {
        $id = $this->book()->json('data.id');

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.kode_booking', Booking::findOrFail($id)->kode_booking);

        $this->patchJson("/api/bookings/{$id}/status", ['status' => Booking::STATUS_LUNAS])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_LUNAS);
    }

    public function test_logged_in_customer_is_linked_to_the_guest(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->book()->assertCreated();

        $this->assertSame($customer->id, Guest::firstOrFail()->user_id);
    }

    public function test_guest_profile_and_vehicle_count_are_saved(): void
    {
        $this->book([
            'guest_email' => 'rina@example.com',
            'guest_birth_date' => '1995-04-17',
            'guest_origin' => 'Bandung, Jawa Barat',
            'guest_type' => 'Instansi',
            'vehicle_count' => 3,
        ])->assertCreated();

        $guest = Guest::firstOrFail();

        $this->assertSame('Bandung, Jawa Barat', $guest->origin);
        $this->assertSame('Instansi', $guest->guest_type);
        $this->assertSame(3, Booking::firstOrFail()->vehicle_count);
    }

    // ------------------------------------------------- beda dari jalur admin

    /**
     * Kelonggaran tanggal lampau ada untuk mencatat walk-in yang sudah
     * menginap — itu pekerjaan admin, bukan sesuatu yang masuk akal dilakukan
     * pengunjung. Test kembar di BookingTest menjaga sisi sebaliknya.
     */
    public function test_visitor_cannot_book_a_past_date(): void
    {
        $this->book([
            'check_in' => Carbon::today()->subDays(3)->toDateString(),
            'check_out' => Carbon::today()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['check_in']);
    }

    /** Tanpa kontak, booking tidak bisa ditindaklanjuti siapa pun. */
    public function test_phone_is_required(): void
    {
        $this->book(['guest_phone' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest_phone']);
    }

    // ------------------------------------------------------ penjagaan sama

    public function test_occupied_dates_are_rejected(): void
    {
        $this->book()->assertCreated();

        $this->book(['guest_phone' => '081200000002'])->assertStatus(422);
    }

    public function test_pax_outside_capacity_is_rejected(): void
    {
        $this->book(['pax' => 99])->assertStatus(422);
    }

    public function test_inactive_item_cannot_be_booked(): void
    {
        $this->book(['item_id' => $this->item('Kamar Standard')->id, 'pax' => 8])
            ->assertStatus(422);
    }

    public function test_item_of_inactive_category_cannot_be_booked(): void
    {
        $item = $this->item();
        $item->category->update(['status' => 'Nonaktif']);

        $this->book(['item_id' => $item->id])->assertStatus(422);
    }

    /** Mode pembayaran yang tidak ditawarkan item ditolak, bukan diabaikan. */
    public function test_payment_mode_must_match_the_item_policy(): void
    {
        $item = $this->item();
        $item->update(['payment_mode' => 'Full Payment']);

        $this->book(['payment_mode' => 'DP + Pelunasan'])->assertStatus(422);

        $this->book(['payment_mode' => 'Full Payment'])->assertCreated();
    }

    public function test_addon_from_another_villa_is_rejected(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();

        // Lepas seluruh tautan lalu tautkan HANYA ke villa lain — seeder
        // menautkan sebagian add-on ke kedua villa, jadi memilih add-on
        // "milik villa lain" begitu saja tidak membuktikan apa pun.
        $otherCategory = Category::where('slug', '!=', 'villa-de-corrinna')->firstOrFail();
        $addon->categories()->sync([$otherCategory->id]);
        $addon->items()->sync([]);

        $this->assertNotSame($otherCategory->id, $this->item()->category_id);

        $this->book(['addons' => [['addon_id' => $addon->id, 'qty' => 1]]])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- survey

    public function test_booking_carries_the_chosen_survey_slot(): void
    {
        $checkIn = Carbon::today()->addDays(30);

        $slot = $this->getJson('/api/villas/villa-de-corrinna/survey-slots?check_in='.$checkIn->toDateString())
            ->json('data.slots.0');

        $this->book([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'survey' => [
                'date' => $slot['date'],
                'session' => $slot['session'],
                'notes' => 'mohon setelah jam 10',
            ],
        ])->assertCreated();

        $survey = Survey::firstOrFail();

        // Muncul di papan admin B4 lengkap dengan tautan booking-nya.
        $this->assertSame($slot['date'], $survey->scheduled_date->toDateString());
        $this->assertNotNull($survey->booking_id);
        $this->assertSame(Survey::STATUS_TERJADWAL, $survey->status);
    }

    public function test_unavailable_survey_slot_is_rejected(): void
    {
        $checkIn = Carbon::today()->addDays(30);

        $this->book([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            // H-1: jauh melewati batas H-7.
            'survey' => ['date' => $checkIn->copy()->subDay()->toDateString(), 'session' => 'Pagi'],
        ])->assertStatus(422);

        // Booking gagal berarti tidak ada apa pun yang tertinggal.
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Survey::count());
    }
}
