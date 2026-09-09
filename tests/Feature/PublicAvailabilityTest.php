<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kalender pilih tanggal A4 — endpoint publik `availability` & `quote`.
 *
 * Keduanya ada supaya frontend tidak menyalin dua aturan yang sudah hidup di
 * backend: malam mana yang terpakai (scope `overlapping`) dan bagaimana malam
 * dinilai weekday/weekend (`BookingPricing`). Test di sini menjaga agar
 * jawabannya tetap sama dengan yang dipakai saat booking benar-benar dibuat.
 */
class PublicAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function villa(): Category
    {
        return Category::where('slug', 'villa-de-corrinna')->firstOrFail();
    }

    private function bookItem(Item $item, string $checkIn, string $checkOut): void
    {
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

        $this->postJson('/api/bookings', [
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '0812'.$item->id.random_int(100000, 999999),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'pax' => $item->cap_min,
        ])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function availability(array $query = []): array
    {
        $response = $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/villas/villa-de-corrinna/availability?'.http_build_query($query));

        $response->assertOk();

        return $response->json('data');
    }

    // ------------------------------------------------------- availability

    public function test_availability_is_public(): void
    {
        $this->getJson('/api/villas/villa-de-corrinna/availability')->assertOk();
    }

    /**
     * Yang dikembalikan adalah malam MENGINAP. Booking 14-17 memakai malam
     * 14, 15, 16 — malam 17 tidak, karena tamu sudah check-out pagi itu dan
     * kamarnya bisa ditempati tamu berikutnya.
     */
    public function test_booked_nights_exclude_the_checkout_night(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();
        $this->bookItem($item, '2026-08-14', '2026-08-17');

        $nights = collect($this->availability(['from' => '2026-08-01', 'to' => '2026-08-31'])['items'])
            ->firstWhere('id', $item->id)['booked_nights'];

        $this->assertSame(['2026-08-14', '2026-08-15', '2026-08-16'], $nights);
    }

    /**
     * Villa penuh hanya bila SEMUA kamarnya terpakai pada malam itu — irisan,
     * bukan gabungan. Satu kamar terisi tidak menutup villa.
     */
    public function test_fully_booked_nights_are_the_intersection_of_all_items(): void
    {
        $items = $this->villa()->activeItems()->orderBy('id')->get();
        $this->assertGreaterThan(1, $items->count(), 'Seeder harus punya >1 item aktif agar test ini bermakna.');

        // Tumpang tindih hanya pada malam 15.
        $this->bookItem($items->first(), '2026-08-14', '2026-08-16');
        $this->bookItem($items->last(), '2026-08-15', '2026-08-17');

        $data = $this->availability(['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(['2026-08-15'], $data['fully_booked_nights']);
    }

    public function test_booking_outside_the_window_is_not_reported(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();
        $this->bookItem($item, '2026-12-24', '2026-12-27');

        $nights = collect($this->availability(['from' => '2026-08-01', 'to' => '2026-08-31'])['items'])
            ->firstWhere('id', $item->id)['booked_nights'];

        $this->assertSame([], $nights);
    }

    /** Booking yang dibatalkan melepaskan tanggalnya — kalender harus ikut. */
    public function test_cancelled_booking_frees_its_nights(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();
        $this->bookItem($item, '2026-08-14', '2026-08-16');

        $booking = Booking::where('item_id', $item->id)->firstOrFail();
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => 'Dibatalkan'])->assertOk();

        $nights = collect($this->availability(['from' => '2026-08-01', 'to' => '2026-08-31'])['items'])
            ->firstWhere('id', $item->id)['booked_nights'];

        $this->assertSame([], $nights);
    }

    public function test_availability_rejects_a_malformed_window(): void
    {
        $this->getJson('/api/villas/villa-de-corrinna/availability?from=kemarin')->assertStatus(422);
    }

    // -------------------------------------------------------------- quote

    /**
     * Definisi malam weekend adalah SABTU & MINGGU. Frontend sempat memakai
     * Jumat & Sabtu, sehingga harga di layar berbeda dari yang ditagihkan —
     * itulah alasan endpoint ini ada.
     */
    public function test_quote_counts_saturday_and_sunday_as_weekend(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();

        // 14 Agustus 2026 = Jumat. Malam menginap: Jumat, Sabtu, Minggu.
        $response = $this->getJson(
            "/api/villas/villa-de-corrinna/quote?item_id={$item->id}&check_in=2026-08-14&check_out=2026-08-17"
        );

        $response->assertOk()
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.weekday_nights', 1)
            ->assertJsonPath('data.weekend_nights', 2);

        $expected = $item->price_weekday + (2 * $item->price_weekend);
        $this->assertSame($expected, $response->json('data.subtotal'));
    }

    /** Harga yang dikutip harus sama persis dengan yang dicatat booking. */
    public function test_quote_matches_the_subtotal_of_a_real_booking(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();

        $quote = $this->getJson(
            "/api/villas/villa-de-corrinna/quote?item_id={$item->id}&check_in=2026-08-14&check_out=2026-08-17"
        )->json('data');

        $this->bookItem($item, '2026-08-14', '2026-08-17');
        $booking = Booking::where('item_id', $item->id)->firstOrFail();

        $this->assertSame($booking->subtotal_item, $quote['subtotal']);
        $this->assertSame($booking->nights, $quote['nights']);
    }

    public function test_quote_reports_an_occupied_range_as_unavailable(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();
        $this->bookItem($item, '2026-08-14', '2026-08-17');

        $this->getJson("/api/villas/villa-de-corrinna/quote?item_id={$item->id}&check_in=2026-08-15&check_out=2026-08-16")
            ->assertOk()
            ->assertJsonPath('data.available', false);

        // Malam check-out tetap bebas.
        $this->getJson("/api/villas/villa-de-corrinna/quote?item_id={$item->id}&check_in=2026-08-17&check_out=2026-08-18")
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    /**
     * Item harus milik villa di URL. Tanpa penjagaan ini, tarif kamar villa
     * lain bisa ditanyakan lewat slug mana pun dan angkanya terlihat sah.
     */
    public function test_quote_rejects_an_item_from_another_villa(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();

        $this->getJson("/api/villas/villa-cendana-wangi/quote?item_id={$item->id}&check_in=2026-08-14&check_out=2026-08-17")
            ->assertStatus(404);
    }

    public function test_quote_rejects_checkout_before_checkin(): void
    {
        $item = $this->villa()->activeItems()->orderBy('id')->firstOrFail();

        $this->getJson("/api/villas/villa-de-corrinna/quote?item_id={$item->id}&check_in=2026-08-17&check_out=2026-08-14")
            ->assertStatus(422);
    }
}
