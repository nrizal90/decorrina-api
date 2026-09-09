<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Booking Core (Fase 3) — jalur admin B3.
 *
 * Keputusan 2026-09-08 yang diuji di sini: booking menunjuk ITEM (bukan
 * kategori), dan mode pembayaran mengikuti pengaturan item.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    /** Kamar Superior: Full Payment, kapasitas 8-12, 4jt/4,8jt. */
    private function item(string $name = 'Kamar Superior'): Item
    {
        return Item::where('name', $name)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'item_id' => $this->item()->id,
            'guest_name' => 'Rina Pratiwi',
            'guest_phone' => '081234567890',
            // Senin-Rabu 2026: dua malam weekday.
            'check_in' => '2026-08-17',
            'check_out' => '2026-08-19',
            'pax' => 10,
        ], $overrides);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $response = $this->postJson('/api/bookings', $this->payload($overrides));
        $response->assertCreated();

        return Booking::findOrFail($response->json('data.id'));
    }

    // ----------------------------------------------------------- kode booking

    public function test_booking_code_follows_the_documented_format(): void
    {
        $booking = $this->createBooking();

        $this->assertMatchesRegularExpression('/^DCG-\d{4}-\d{5}$/', $booking->kode_booking);
        $this->assertSame('DCG-'.now()->year.'-00001', $booking->kode_booking);
    }

    public function test_booking_codes_increment_per_tenant(): void
    {
        $first = $this->createBooking();
        $second = $this->createBooking(['check_in' => '2026-09-07', 'check_out' => '2026-09-09']);

        $this->assertSame('DCG-'.now()->year.'-00001', $first->kode_booking);
        $this->assertSame('DCG-'.now()->year.'-00002', $second->kode_booking);
    }

    // ----------------------------------------------------------------- harga

    public function test_weekday_and_weekend_nights_are_priced_separately(): void
    {
        // Jumat 2026-08-21 -> Senin 2026-08-24: Jumat (weekday) + Sabtu &
        // Minggu (weekend) = 4.000.000 + 4.800.000 + 4.800.000.
        $booking = $this->createBooking([
            'check_in' => '2026-08-21',
            'check_out' => '2026-08-24',
        ]);

        $this->assertSame(3, $booking->nights);
        $this->assertSame(13_600_000, $booking->subtotal_item);
        $this->assertSame(13_600_000, $booking->total);
    }

    public function test_checkout_night_is_not_charged(): void
    {
        $booking = $this->createBooking();

        // 17 & 18 Agustus menginap; 19 Agustus hari pulang, tidak dihitung.
        $this->assertSame(2, $booking->nights);
        $this->assertSame(8_000_000, $booking->subtotal_item);
    }

    public function test_prices_are_snapshotted_and_survive_master_data_changes(): void
    {
        $booking = $this->createBooking();
        $originalTotal = $booking->total;

        $this->item()->update(['price_weekday' => 99_000_000]);

        // Inti aturannya: booking adalah catatan kesepakatan, bukan tampilan
        // harga master hari ini.
        $this->assertSame($originalTotal, $booking->fresh()->total);
        $this->assertSame(4_000_000, $booking->fresh()->price_weekday);
    }

    public function test_addons_are_charged_by_quantity_and_snapshotted(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail(); // 150.000

        $booking = $this->createBooking([
            'addons' => [['addon_id' => $addon->id, 'qty' => 2]],
        ]);

        $this->assertSame(300_000, $booking->subtotal_addons);
        $this->assertSame(8_300_000, $booking->total);
        $this->assertSame(150_000, $booking->addons()->first()->unit_price);
    }

    public function test_inactive_addon_cannot_be_booked(): void
    {
        // Paket BBQ diseed Nonaktif — tidak ditawarkan ke customer, jadi tidak
        // boleh ditagihkan lewat booking manual pun.
        $bbq = Addon::where('name', 'Paket BBQ')->firstOrFail();

        $this->postJson('/api/bookings', $this->payload([
            'addons' => [['addon_id' => $bbq->id, 'qty' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Booking::count());
    }

    public function test_addon_from_another_villa_cannot_be_booked(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();
        $item = $this->item();

        // Lepas seluruh tautan lalu tautkan HANYA ke kategori lain, sehingga
        // add-on ini tidak berlaku untuk kamar yang dipesan.
        $otherCategory = Category::where('slug', '!=', 'villa-de-corrinna')->firstOrFail();
        $addon->categories()->sync([$otherCategory->id]);
        $addon->items()->sync([]);

        $this->assertNotSame($otherCategory->id, $item->category_id);

        $this->postJson('/api/bookings', $this->payload([
            'addons' => [['addon_id' => $addon->id, 'qty' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_addon_linked_to_the_item_itself_is_accepted(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();
        $item = $this->item();

        // Tertaut langsung ke item (bukan lewat kategori) — juga sah.
        $addon->categories()->sync([]);
        $addon->items()->sync([$item->id]);

        $this->postJson('/api/bookings', $this->payload([
            'addons' => [['addon_id' => $addon->id, 'qty' => 1]],
        ]))->assertCreated();
    }

    public function test_same_addon_cannot_be_sent_twice(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();

        // Sebelum aturan `distinct`, ini menabrak unique(booking_id, addon_id)
        // dan keluar sebagai error 500, bukan pesan validasi.
        $this->postJson('/api/bookings', $this->payload([
            'addons' => [
                ['addon_id' => $addon->id, 'qty' => 1],
                ['addon_id' => $addon->id, 'qty' => 2],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('addons.1.addon_id');

        $this->assertSame(0, Booking::count());
    }

    // ------------------------------------------------- mode pembayaran (item)

    public function test_payment_mode_is_copied_from_the_item(): void
    {
        $superior = $this->createBooking();
        $this->assertSame('Full Payment', $superior->payment_mode);
        $this->assertNull($superior->dp_minimum);

        // Kamar Deluxe diseed dengan DP + Pelunasan.
        $deluxe = $this->createBooking([
            'item_id' => $this->item('Kamar Deluxe')->id,
            'pax' => 12,
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-07',
        ]);

        $this->assertSame('DP + Pelunasan', $deluxe->payment_mode);
        $this->assertNotNull($deluxe->dp_minimum);
    }

    // ----------------------------------------------------------- ketersediaan

    public function test_overlapping_dates_on_the_same_item_are_rejected(): void
    {
        $existing = $this->createBooking();

        $this->postJson('/api/bookings', $this->payload([
            'check_in' => '2026-08-18',
            'check_out' => '2026-08-20',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, Booking::where('item_id', $existing->item_id)->count());
    }

    public function test_checkout_day_may_be_another_guests_checkin_day(): void
    {
        $this->createBooking(); // 17 -> 19

        // Tamu berikutnya masuk 19 Agustus, hari yang sama dengan check-out.
        $this->postJson('/api/bookings', $this->payload([
            'check_in' => '2026-08-19',
            'check_out' => '2026-08-21',
        ]))->assertCreated();
    }

    public function test_cancelled_booking_releases_its_dates(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => Booking::STATUS_DIBATALKAN]);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();
    }

    public function test_same_dates_on_a_different_item_are_allowed(): void
    {
        $this->createBooking();

        $this->postJson('/api/bookings', $this->payload([
            'item_id' => $this->item('Kamar Deluxe')->id,
            'pax' => 12,
        ]))->assertCreated();
    }

    public function test_availability_endpoint_reports_conflicts(): void
    {
        $booking = $this->createBooking();

        $free = $this->getJson('/api/bookings/availability?item_id='.$booking->item_id.'&check_in=2026-12-01&check_out=2026-12-03');
        $free->assertOk()->assertJsonPath('data.available', true);

        $taken = $this->getJson('/api/bookings/availability?item_id='.$booking->item_id.'&check_in=2026-08-18&check_out=2026-08-20');
        $taken->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.conflicts.0.kode_booking', $booking->kode_booking);
    }

    // -------------------------------------------------------------- kapasitas

    public function test_pax_outside_item_capacity_is_rejected(): void
    {
        // Kamar Superior: 8-12 orang.
        $this->postJson('/api/bookings', $this->payload(['pax' => 3]))->assertStatus(422);
        $this->postJson('/api/bookings', $this->payload(['pax' => 20]))->assertStatus(422);
    }

    public function test_inactive_item_cannot_be_booked(): void
    {
        // Kamar Standard diseed berstatus Nonaktif.
        $this->postJson('/api/bookings', $this->payload([
            'item_id' => $this->item('Kamar Standard')->id,
            'pax' => 8,
        ]))->assertStatus(422);
    }

    /**
     * Menonaktifkan villa harus menutup seluruh kamarnya. Layar admin sudah
     * menyaring, tapi penjagaannya harus di backend — jalur API langsung
     * sebelumnya masih meloloskan item aktif di bawah kategori nonaktif.
     */
    public function test_item_of_inactive_category_cannot_be_booked(): void
    {
        $item = $this->item();
        $item->category->update(['status' => 'Nonaktif']);

        $this->postJson('/api/bookings', $this->payload(['item_id' => $item->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Villa untuk item ini sedang tidak aktif dan tidak bisa dipesan.');
    }

    public function test_backdated_checkin_is_allowed_for_walk_in_guests(): void
    {
        // Booking manual juga dipakai mencatat tamu yang sudah terlanjur
        // menginap, jadi tanggal lampau TIDAK ditolak. Test ini menjaga agar
        // keputusan itu tidak diam-diam dibalik.
        $this->postJson('/api/bookings', $this->payload([
            'check_in' => now()->subDays(5)->toDateString(),
            'check_out' => now()->subDays(3)->toDateString(),
        ]))->assertCreated();
    }

    public function test_checkout_must_be_after_checkin(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'check_in' => '2026-08-17',
            'check_out' => '2026-08-17',
        ]))->assertStatus(422)->assertJsonValidationErrors('check_out');
    }

    // ------------------------------------------------------------------- tamu

    public function test_guest_is_created_from_booking_data(): void
    {
        $booking = $this->createBooking();

        $this->assertSame('Rina Pratiwi', $booking->guest->name);
        $this->assertSame('081234567890', $booking->guest->phone);
    }

    public function test_repeat_guest_is_matched_by_phone_instead_of_duplicated(): void
    {
        $first = $this->createBooking();
        $second = $this->createBooking(['check_in' => '2026-09-07', 'check_out' => '2026-09-09']);

        $this->assertSame($first->guest_id, $second->guest_id);
        $this->assertSame(1, Guest::where('phone', '081234567890')->count());
    }

    // ---------------------------------------------------------- state machine

    public function test_status_follows_the_state_machine(): void
    {
        $booking = $this->createBooking();

        $this->assertSame(Booking::STATUS_MENUNGGU, $booking->status);

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_DP])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_DP);

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])
            ->assertOk();

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_SELESAI])
            ->assertOk();
    }

    public function test_status_cannot_skip_backwards(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => Booking::STATUS_LUNAS]);

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_DP])
            ->assertStatus(422);

        $this->assertSame(Booking::STATUS_LUNAS, $booking->fresh()->status);
    }

    public function test_final_statuses_cannot_be_changed(): void
    {
        $booking = $this->createBooking();
        $booking->update(['status' => Booking::STATUS_DIBATALKAN]);

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Booking berstatus "Dibatalkan" sudah final dan tidak bisa diubah lagi.');
    }

    public function test_allowed_transitions_are_exposed_to_the_frontend(): void
    {
        $booking = $this->createBooking();

        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_transitions', [
                Booking::STATUS_DP,
                Booking::STATUS_LUNAS,
                Booking::STATUS_DIBATALKAN,
            ]);
    }

    // ------------------------------------------------------------------ daftar

    public function test_list_can_be_filtered_by_status_and_searched(): void
    {
        $a = $this->createBooking();
        $b = $this->createBooking([
            'guest_name' => 'Budi Santoso',
            'guest_phone' => '08999',
            'check_in' => '2026-09-07',
            'check_out' => '2026-09-09',
        ]);
        $b->update(['status' => Booking::STATUS_LUNAS]);

        $lunas = $this->getJson('/api/bookings?status='.urlencode(Booking::STATUS_LUNAS));
        $lunas->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($b->kode_booking, $lunas->json('data.0.kode_booking'));

        $byName = $this->getJson('/api/bookings?q=Rina');
        $byName->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($a->kode_booking, $byName->json('data.0.kode_booking'));

        $byCode = $this->getJson('/api/bookings?q='.$a->kode_booking);
        $byCode->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_villa_name_is_flattened_for_the_admin_table(): void
    {
        $booking = $this->createBooking();

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.villa', 'Villa De Corrinna')
            ->assertJsonPath('data.0.item.name', 'Kamar Superior');

        $this->assertNotNull($booking->kode_booking);
    }

    // ------------------------------------------------------------------- RBAC

    public function test_customer_cannot_change_booking_status(): void
    {
        // Booking dibuat lebih dulu oleh admin, supaya yang diuji benar-benar
        // penolakan izin — bukan 404 karena datanya tidak ada.
        $booking = $this->createBooking();

        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        // Customer punya bookings:index & bookings:show, tapi TIDAK punya
        // bookings:update-status.
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])
            ->assertForbidden();

        $this->assertSame(Booking::STATUS_MENUNGGU, $booking->fresh()->status);
    }

    public function test_customer_cannot_create_bookings_through_the_admin_endpoint(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        // bookings:store memang dimiliki customer (alur A6 nanti), tapi tanpa
        // konteks tenant ia tak bisa menulis lewat jalur admin ini.
        $this->postJson('/api/bookings', $this->payload())->assertStatus(422);
    }
}
