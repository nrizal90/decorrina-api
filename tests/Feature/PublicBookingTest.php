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
use Illuminate\Support\Facades\DB;
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

    /** Audit T-01b/T-08: customer tanpa tenant TIDAK boleh menyentuh jalur admin. */
    public function test_customer_cannot_use_admin_booking_endpoints(): void
    {
        $this->book()->assertCreated();
        $booking = Booking::firstOrFail();

        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/bookings')->assertForbidden();
        $this->getJson("/api/bookings/{$booking->id}")->assertForbidden();
        $this->postJson('/api/bookings', $this->payload())->assertForbidden();
    }

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

    /** Audit T-03: jalur publik tidak boleh membocorkan profil tamu lama, dan tidak boleh membajaknya. */
    public function test_public_response_does_not_leak_stored_guest_profile(): void
    {
        $this->book(['guest_email' => 'rina@example.com', 'guest_birth_date' => '1995-04-17', 'guest_origin' => 'Bandung'])
            ->assertCreated();

        // "Penyerang" memesan lagi dengan nomor yang sama, tanpa profil.
        $later = Carbon::today()->addDays(40);
        $this->book(['check_in' => $later->toDateString(), 'check_out' => $later->copy()->addDay()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.guest.name', 'Rina Pratiwi')
            ->assertJsonMissingPath('data.guest.email')
            ->assertJsonMissingPath('data.guest.birth_date')
            ->assertJsonMissingPath('data.guest.origin')
            ->assertJsonMissingPath('data.allowed_transitions');
    }

    public function test_existing_guest_is_not_linked_to_the_logged_in_account(): void
    {
        $this->book()->assertCreated(); // tamu anonim lebih dulu

        $attacker = User::factory()->create(['tenant_id' => null]);
        $attacker->assignRole('customer');
        Sanctum::actingAs($attacker);

        $later = Carbon::today()->addDays(40);
        $this->book(['check_in' => $later->toDateString(), 'check_out' => $later->copy()->addDay()->toDateString()])
            ->assertCreated();

        $this->assertNull(Guest::firstOrFail()->user_id);
        $this->assertSame(1, Guest::count());
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
    /** Audit T-04: rentang tak berbatas = CPU DoS + kalender terblokir 8000 tahun. */
    public function test_stay_length_and_horizon_are_capped(): void
    {
        $in = Carbon::today()->addDays(20);
        $this->book(['check_out' => $in->copy()->addDays(31)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('check_out');
        $this->book(['check_out' => $in->copy()->addDays(30)->toDateString()])
            ->assertCreated();

        $far = Carbon::today()->addMonths(13);
        $this->book(['check_in' => $far->toDateString(), 'check_out' => $far->copy()->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('check_out');

        $this->withHeader('X-Tenant', 'decorinna')
            ->getJson("/api/villas/villa-de-corrinna/quote?item_id={$this->item()->id}&check_in=2026-10-01&check_out=9999-12-31")
            ->assertUnprocessable();
    }

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

    // ------------------------------------------------------ cek status (A15)

    private function lookup(string $code, string $contact)
    {
        return $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/public/bookings/lookup?'.http_build_query(['code' => $code, 'contact' => $contact]));
    }

    public function test_lookup_finds_a_booking_by_code_and_phone(): void
    {
        $code = $this->book(['guest_phone' => '081234567890', 'guest_email' => 'rina@example.com'])->json('data.kode_booking');

        $this->lookup($code, '081234567890')->assertOk()->assertJsonPath('data.kode_booking', $code);
        $this->lookup($code, 'rina@example.com')->assertOk();
    }

    /** "0812...", "+62 812...", "62-812..." adalah nomor yang sama. */
    public function test_lookup_normalizes_phone_and_email_formatting(): void
    {
        $code = $this->book(['guest_phone' => '081234567890', 'guest_email' => 'rina@example.com'])->json('data.kode_booking');

        $this->lookup(strtolower($code), '+62 812-3456-7890')->assertOk();
        $this->lookup($code, '62812 3456 7890')->assertOk();
        $this->lookup($code, 'RINA@Example.com')->assertOk();
    }

    /**
     * Kode benar tapi kontak salah HARUS terlihat sama dengan kode salah:
     * kodenya berurutan dan mudah ditebak, jangan konfirmasi ke penebak.
     */
    public function test_lookup_does_not_reveal_whether_the_code_exists(): void
    {
        $code = $this->book()->json('data.kode_booking');

        $wrongContact = $this->lookup($code, '080000000000')->assertStatus(404);
        $wrongCode = $this->lookup('DCG-2026-99999', '081234567890')->assertStatus(404);

        $this->assertSame($wrongContact->json('message'), $wrongCode->json('message'));
    }

    /** T-01: kontak yang ternormalisasi jadi '' tidak boleh cocok dengan email/telepon kosong. */
    public function test_lookup_rejects_empty_normalized_contact(): void
    {
        $code = $this->book(['guest_phone' => '081234567890'])->json('data.kode_booking');

        // Maks 5 lookup: endpoint ber-throttle 6/menit.
        foreach (['x', '-', 'abc', '+'] as $junk) {
            $this->lookup($code, $junk)->assertStatus(404);
        }
        $this->lookup($code, '081234567890')->assertOk();
    }

    public function test_lookup_rejects_contactless_booking(): void
    {
        $code = $this->book()->json('data.kode_booking');
        DB::table('guests')->update(['phone' => null, 'email' => null]);

        $this->lookup($code, 'x')->assertStatus(404);
    }

    public function test_lookup_requires_both_fields(): void
    {
        $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/public/bookings/lookup?code=DCG-2026-00001')
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
