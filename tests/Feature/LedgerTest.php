<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Item;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Akuntansi & Keuangan (B9, Fase 7).
 *
 * Yang dijaga: pemasukan booking lahir OTOMATIS dari perubahan status di papan
 * B3 (DP -> dp_minimum, Lunas -> sisa), tidak pernah melebihi total booking,
 * dan bagi hasil Cendana Wangi 70:30 dihitung atas uang yang tercatat masuk.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function book(string $itemName = 'Kamar Superior', array $extra = [], ?string $villaSlug = null): Booking
    {
        // Dicari lewat slug villa bila diberi — nama item di seeder bisa
        // berbeda dari DB dev, slug kategori tidak.
        $item = $villaSlug === null
            ? Item::where('name', $itemName)->firstOrFail()
            : Category::where('slug', $villaSlug)->firstOrFail()->activeItems()->firstOrFail();
        $checkIn = Carbon::today()->addDays(20);

        $response = $this->postJson('/api/bookings', array_merge([
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '0812'.random_int(100000, 999999),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => $item->cap_min,
        ], $extra));

        $response->assertCreated();

        return Booking::findOrFail($response->json('data.id'));
    }

    private function setStatus(Booking $booking, string $status): void
    {
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => $status])->assertOk();
    }

    private function incomeFor(Booking $booking): int
    {
        return (int) LedgerEntry::income()->where('booking_id', $booking->id)->sum('amount');
    }

    // --------------------------------------------------- pencatatan otomatis

    public function test_marking_paid_in_full_records_the_whole_total(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_LUNAS);

        $this->assertSame($booking->total, $this->incomeFor($booking));
    }

    /** DP mencatat dp_minimum; pelunasan mencatat SISANYA, bukan total lagi. */
    public function test_dp_then_settlement_never_exceeds_the_total(): void
    {
        $item = Item::where('name', 'Kamar Superior')->firstOrFail();
        $item->update(['payment_mode' => 'DP + Pelunasan', 'dp_minimum' => 1_000_000]);

        $booking = $this->book();

        $this->setStatus($booking, Booking::STATUS_DP);
        $this->assertSame(1_000_000, $this->incomeFor($booking));

        $this->setStatus($booking, Booking::STATUS_LUNAS);
        $this->assertSame($booking->total, $this->incomeFor($booking));
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    /** Add-on dicatat sebagai kategori sendiri supaya rincian bulanan benar. */
    public function test_addons_are_recorded_under_their_own_category(): void
    {
        $addon = Addon::where('name', 'Extra Bed')->firstOrFail();
        $booking = $this->book('Kamar Superior', ['addons' => [['addon_id' => $addon->id, 'qty' => 2]]]);

        $this->setStatus($booking, Booking::STATUS_LUNAS);

        $byCategory = LedgerEntry::where('booking_id', $booking->id)->pluck('amount', 'category');

        $this->assertSame($booking->subtotal_item, $byCategory['Booking Villa']);
        $this->assertSame($booking->subtotal_addons, $byCategory['Add-on']);
    }

    /** Kebijakan refund belum diputuskan — pembatalan tidak membalik apa pun. */
    public function test_cancellation_does_not_reverse_recorded_income(): void
    {
        $item = Item::where('name', 'Kamar Superior')->firstOrFail();
        $item->update(['payment_mode' => 'DP + Pelunasan', 'dp_minimum' => 1_000_000]);

        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_DP);
        $this->setStatus($booking, Booking::STATUS_DIBATALKAN);

        $this->assertSame(1_000_000, $this->incomeFor($booking));
    }

    public function test_pending_booking_records_nothing(): void
    {
        $this->book();

        $this->assertSame(0, LedgerEntry::count());
    }

    /** Baris otomatis tidak punya pencatat; baris manual punya. */
    public function test_automatic_entries_are_flagged(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_LUNAS);

        $this->postJson('/api/ledger', [
            'entry_date' => Carbon::today()->toDateString(),
            'description' => 'Beli sabun',
            'type' => 'Pengeluaran',
            'category' => 'Operasional',
            'amount' => 50_000,
        ])->assertCreated();

        $rows = collect($this->getJson('/api/ledger')->json('data.entries'));

        $this->assertTrue($rows->firstWhere('booking_id', $booking->id)['is_automatic']);
        $this->assertFalse($rows->firstWhere('description', 'Beli sabun')['is_automatic']);
    }

    // ------------------------------------------------------------- manual

    public function test_manual_entry_requires_valid_type_and_positive_amount(): void
    {
        $this->postJson('/api/ledger', [
            'entry_date' => '2026-09-10',
            'description' => 'x',
            'type' => 'Hutang',
            'category' => 'Lain-lain',
            'amount' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors(['type', 'amount']);
    }

    public function test_totals_follow_the_date_range(): void
    {
        $this->postJson('/api/ledger', ['entry_date' => '2026-09-05', 'description' => 'A', 'type' => 'Pemasukan', 'category' => 'Lain-lain', 'amount' => 300_000])->assertCreated();
        $this->postJson('/api/ledger', ['entry_date' => '2026-09-06', 'description' => 'B', 'type' => 'Pengeluaran', 'category' => 'Operasional', 'amount' => 100_000])->assertCreated();
        $this->postJson('/api/ledger', ['entry_date' => '2026-10-01', 'description' => 'C', 'type' => 'Pemasukan', 'category' => 'Lain-lain', 'amount' => 999_999])->assertCreated();

        $data = $this->getJson('/api/ledger?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');

        $this->assertCount(2, $data['entries']);
        $this->assertSame(['income' => 300_000, 'expense' => 100_000, 'net' => 200_000], $data['totals']);
        $this->assertSame(['2026-10', '2026-09'], $data['months']);
    }

    // ------------------------------------------------------------ bulanan

    public function test_monthly_summary_breaks_down_by_category(): void
    {
        $this->postJson('/api/ledger', ['entry_date' => '2026-09-05', 'description' => 'A', 'type' => 'Pemasukan', 'category' => 'Catering', 'amount' => 300_000])->assertCreated();
        $this->postJson('/api/ledger', ['entry_date' => '2026-09-06', 'description' => 'B', 'type' => 'Pengeluaran', 'category' => 'Gaji', 'amount' => 120_000])->assertCreated();

        $monthly = $this->getJson('/api/ledger?month=2026-09')->assertOk()->json('data.monthly');

        $this->assertSame(300_000, $monthly['income']);
        $this->assertSame(120_000, $monthly['expense']);
        $this->assertSame(180_000, $monthly['net']);
        $this->assertSame([['category' => 'Catering', 'amount' => 300_000]], $monthly['income_by_category']);
    }

    // --------------------------------------------------------- bagi hasil

    /** 70:30 dipatok di config (menunggu modul joint venture). */
    public function test_profit_share_splits_cendana_wangi_income_70_30(): void
    {
        // Seeder tidak memberi Cendana Wangi item; buat satu agar bisa dipesan.
        $cendana = Category::where('slug', 'villa-cendana-wangi')->firstOrFail();
        Item::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $cendana->tenant_id,
            'category_id' => $cendana->id,
            'name' => 'Kamar Cendana',
            'capacity_type' => 'Rentang',
            'cap_min' => 2,
            'cap_max' => 6,
            'price_weekday' => 1_900_000,
            'price_weekend' => 2_400_000,
            'payment_mode' => 'Full Payment',
            'status' => 'Aktif',
        ]);

        $booking = $this->book('', [], 'villa-cendana-wangi');
        $this->setStatus($booking, Booking::STATUS_LUNAS);

        // Pemasukan villa lain tidak boleh ikut.
        $other = $this->book('Kamar Superior');
        $this->setStatus($other, Booking::STATUS_LUNAS);

        $share = $this->getJson('/api/ledger?profit_share=villa-cendana-wangi')->assertOk()->json('data.profit_share');

        $this->assertSame(70, $share['owner_pct']);
        $this->assertCount(1, $share['rows']);

        $row = $share['rows'][0];
        $this->assertSame($booking->total, $row['total']);
        $this->assertSame((int) round($booking->total * 0.7), $row['owner_share']);
        // Keduanya selalu tepat berjumlah total — tidak ada rupiah yang hilang.
        $this->assertSame($row['total'], $row['owner_share'] + $row['operator_share']);
    }

    public function test_villa_without_a_scheme_has_no_profit_share(): void
    {
        $this->assertNull($this->getJson('/api/ledger?profit_share=villa-de-corrinna')->json('data.profit_share'));
    }

    // -------------------------------------------------------------- akses

    /** Keuangan bukan urusan Staff — seeder sengaja mencabut ledger:*. */
    public function test_staff_cannot_see_the_ledger(): void
    {
        $staff = User::factory()->create(['tenant_id' => User::where('email', 'admin@decorinna.test')->firstOrFail()->tenant_id]);
        $staff->assignRole('staff');
        Sanctum::actingAs($staff);

        $this->getJson('/api/ledger')->assertStatus(403);
    }

    public function test_stakeholder_can_read_but_not_write(): void
    {
        $stakeholder = User::factory()->create(['tenant_id' => User::where('email', 'admin@decorinna.test')->firstOrFail()->tenant_id]);
        $stakeholder->assignRole('stakeholder');
        Sanctum::actingAs($stakeholder);

        $this->getJson('/api/ledger')->assertOk();
        $this->postJson('/api/ledger', ['entry_date' => '2026-09-10', 'description' => 'x', 'type' => 'Pemasukan', 'category' => 'Lain-lain', 'amount' => 1])
            ->assertStatus(403);
    }
}
