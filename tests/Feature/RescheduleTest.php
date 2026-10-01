<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HolidaySeason;
use App\Models\Item;
use App\Models\LedgerEntry;
use App\Models\Reschedule;
use App\Models\Survey;
use App\Models\User;
use App\Support\TenantSettings;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reschedule (A13 / B3): tamu mengajukan, admin menyetujui/menolak, admin
 * bisa langsung. Aturan tamu dari config/reschedule.php.
 * Kamar Superior = 4jt weekday / 4,8jt weekend.
 */
class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->guest = User::factory()->create(['tenant_id' => null]);
        $this->guest->assignRole('customer');
    }

    private function item(string $name = 'Kamar Superior'): Item
    {
        return Item::where('name', $name)->firstOrFail();
    }

    /** Senin berikutnya + $weeks minggu — tanggal weekday yang stabil. */
    private function monday(int $weeks = 4): Carbon
    {
        return Carbon::today()->next(Carbon::MONDAY)->addWeeks($weeks);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    /** Booking milik tamu (2 malam weekday) lewat jalur publik dengan token asli. */
    private function guestBooking(?Carbon $checkIn = null, array $extra = []): Booking
    {
        $checkIn ??= $this->monday();

        $this->withToken($this->guest->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant', 'decorinna')
            ->postJson('/api/public/bookings', array_merge([
                'item_id' => $this->item()->id,
                'guest_name' => 'Rina',
                'guest_phone' => '081234567890',
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
                'pax' => 10,
            ], $extra))->assertCreated();
        $this->withoutToken();

        return Booking::latest('id')->firstOrFail();
    }

    /** Pengaturan tenant seperti yang disimpan admin dari layar Settings. */
    private function setting(string $key, mixed $value): void
    {
        TenantSettings::put($this->item()->tenant_id, [$key => $value]);
    }

    private function asGuest(): void
    {
        Sanctum::actingAs($this->guest);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin());
    }

    private function requestMove(Booking $booking, Carbon $checkIn, int $nights = 2, array $extra = [])
    {
        $this->asGuest();

        return $this->postJson("/api/me/bookings/{$booking->id}/reschedule", array_merge([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays($nights)->toDateString(),
            'reason' => 'Ada acara keluarga',
        ], $extra));
    }

    // ------------------------------------------------- ajukan → setujui

    public function test_guest_request_waits_for_admin_and_approval_moves_the_booking(): void
    {
        $booking = $this->guestBooking();
        $original = $booking->check_in->toDateString();
        $target = $this->monday(6);

        $this->requestMove($booking, $target)->assertCreated()
            ->assertJsonPath('data.status', Reschedule::STATUS_MENUNGGU);

        // Belum berubah sampai admin menyetujui.
        $this->assertSame($original, $booking->fresh()->check_in->toDateString());

        $this->asAdmin();
        $id = $this->getJson('/api/reschedules')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', Reschedule::STATUS_DISETUJUI);

        $fresh = $booking->fresh();
        $this->assertSame($target->toDateString(), $fresh->check_in->toDateString());
        $this->assertSame(2, $fresh->nights);
    }

    public function test_rejection_leaves_the_booking_untouched(): void
    {
        $booking = $this->guestBooking();
        $id = $this->requestMove($booking, $this->monday(6))->json('data.id');

        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/reject", ['note' => 'Penuh'])->assertOk()
            ->assertJsonPath('data.status', Reschedule::STATUS_DITOLAK);

        $this->assertSame($this->monday()->toDateString(), $booking->fresh()->check_in->toDateString());
    }

    public function test_approval_cancels_the_pending_survey(): void
    {
        $booking = $this->guestBooking();
        Survey::forceCreate([
            'tenant_id' => $booking->tenant_id, 'category_id' => $this->item()->category_id,
            'booking_id' => $booking->id, 'guest_name' => 'Rina',
            'scheduled_date' => Carbon::today()->addDays(5)->toDateString(),
            'scheduled_time' => '10:00', 'scheduled_end_time' => '11:00', 'status' => Survey::STATUS_TERJADWAL,
        ]);

        $id = $this->requestMove($booking, $this->monday(6))->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk();

        $this->assertSame(Survey::STATUS_DIBATALKAN, Survey::firstOrFail()->status);
    }

    // ------------------------------------------------------- aturan tamu

    public function test_only_one_pending_request_at_a_time(): void
    {
        $booking = $this->guestBooking();
        $this->requestMove($booking, $this->monday(6))->assertCreated();
        $this->requestMove($booking, $this->monday(7))->assertStatus(422);
    }

    public function test_quota_is_enforced_after_approval(): void
    {
        $booking = $this->guestBooking();
        $id = $this->requestMove($booking, $this->monday(6))->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk();

        $this->requestMove($booking->fresh(), $this->monday(8))->assertStatus(422)
            ->assertJsonPath('message', 'Kuota reschedule untuk booking ini sudah habis. Silakan hubungi admin.');
    }

    public function test_quota_is_configurable(): void
    {
        $this->setting('reschedule.quota', 2);

        $booking = $this->guestBooking();
        $id = $this->requestMove($booking, $this->monday(6))->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk();

        $this->requestMove($booking->fresh(), $this->monday(8))->assertCreated();
    }

    public function test_request_after_the_deadline_is_rejected(): void
    {
        // Check-in 2 hari lagi; batas default H-3 sudah lewat.
        $booking = $this->guestBooking(Carbon::today()->addDays(2));

        $this->requestMove($booking, $this->monday(6))->assertStatus(422);
    }

    public function test_changing_nights_is_rejected_unless_configured(): void
    {
        $booking = $this->guestBooking();
        $this->requestMove($booking, $this->monday(6), nights: 3)->assertStatus(422);

        $this->setting('reschedule.allow_change_nights', true);
        $this->requestMove($booking, $this->monday(6), nights: 3)->assertCreated();
    }

    public function test_changing_room_is_rejected_unless_configured(): void
    {
        $booking = $this->guestBooking();
        $deluxe = $this->item('Kamar Deluxe');

        $this->requestMove($booking, $this->monday(6), extra: ['item_id' => $deluxe->id])->assertStatus(422);

        $this->setting('reschedule.allow_change_item', true);
        $this->requestMove($booking, $this->monday(6), extra: ['item_id' => $deluxe->id])->assertCreated();
    }

    public function test_taken_dates_are_rejected_and_rechecked_on_approval(): void
    {
        $booking = $this->guestBooking();
        $target = $this->monday(6);
        $id = $this->requestMove($booking, $target)->json('data.id');

        // Orang lain mengambil tanggalnya sebelum admin memutuskan.
        $this->asAdmin();
        $this->postJson('/api/bookings', [
            'item_id' => $this->item()->id, 'guest_name' => 'Walk-in', 'guest_phone' => '081200000011',
            'check_in' => $target->toDateString(), 'check_out' => $target->copy()->addDay()->toDateString(), 'pax' => 10,
        ])->assertCreated();

        $this->postJson("/api/reschedules/{$id}/approve")->assertStatus(422);
        $this->requestMove($booking, $target)->assertStatus(422);
    }

    public function test_other_users_booking_is_not_found(): void
    {
        $booking = $this->guestBooking();
        $stranger = User::factory()->create(['tenant_id' => null]);
        $stranger->assignRole('customer');
        Sanctum::actingAs($stranger);

        $this->getJson("/api/me/bookings/{$booking->id}/reschedule")->assertNotFound();
        $this->postJson("/api/me/bookings/{$booking->id}/reschedule", [
            'check_in' => $this->monday(6)->toDateString(), 'check_out' => $this->monday(6)->addDays(2)->toDateString(),
        ])->assertNotFound();
    }

    public function test_preview_reports_policy_price_and_availability(): void
    {
        $booking = $this->guestBooking();
        $this->asGuest();

        $target = $this->monday(6);
        $data = $this->getJson("/api/me/bookings/{$booking->id}/reschedule?".http_build_query([
            'check_in' => $target->toDateString(), 'check_out' => $target->copy()->addDays(2)->toDateString(),
        ]))->assertOk()->json('data');

        $this->assertSame(1, $data['policy']['quota']);
        $this->assertNull($data['blocker']);
        $this->assertTrue($data['preview']['available']);
        $this->assertSame(8_000_000, $data['preview']['new_total']);
        $this->assertSame(0, $data['preview']['difference']);
        $this->assertNull($data['preview']['rejection']);
    }

    // ---------------------------------------------------------- selisih

    public function test_paid_booking_moved_to_pricier_dates_owes_the_difference(): void
    {
        $booking = $this->guestBooking();
        $this->asAdmin();
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])->assertOk();

        // Pindah ke Sabtu–Senin: Sabtu & Minggu weekend = 9,6jt (lama 8jt).
        $saturday = $this->monday(6)->subDays(2);
        $id = $this->requestMove($booking, $saturday)->json('data.id');

        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk()
            ->assertJsonPath('data.difference', 1_600_000)
            ->assertJsonPath('data.outstanding', 1_600_000);

        $this->assertSame(9_600_000, $booking->fresh()->total);

        $this->postJson("/api/reschedules/{$id}/difference-paid")->assertOk()
            ->assertJsonPath('data.outstanding', 0);

        $this->assertSame(9_600_000, (int) LedgerEntry::where('booking_id', $booking->id)->sum('amount'));
        $this->postJson("/api/reschedules/{$id}/difference-paid")->assertStatus(422);
    }

    /** DP: selisih ikut pelunasan (Ledger menghitung sisa dari total baru), tidak ditagih terpisah. */
    public function test_dp_booking_difference_is_collected_at_settlement(): void
    {
        $booking = $this->guestBooking(null, ['payment_mode' => 'Full Payment']);
        $booking->update(['dp_minimum' => 2_000_000]);
        $this->asAdmin();
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_DP])->assertOk();

        HolidaySeason::forceCreate([
            'tenant_id' => $booking->tenant_id, 'name' => 'Uji',
            'start_date' => $this->monday(6)->toDateString(), 'end_date' => $this->monday(6)->toDateString(),
        ]);
        $id = $this->requestMove($booking, $this->monday(6))->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/reschedules/{$id}/approve")->assertOk()
            ->assertJsonPath('data.difference', 800_000)
            ->assertJsonPath('data.outstanding', 0);

        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_LUNAS])->assertOk();
        $this->assertSame(8_800_000, (int) LedgerEntry::where('booking_id', $booking->id)->sum('amount'));
    }

    // ------------------------------------------------------------- admin

    public function test_admin_reschedules_directly_without_guest_rules(): void
    {
        // Lewat batas H-3 pun admin tetap bisa.
        $booking = $this->guestBooking(Carbon::today()->addDays(2));
        $this->asAdmin();

        $target = $this->monday(6);
        $this->postJson("/api/bookings/{$booking->id}/reschedule", [
            'check_in' => $target->toDateString(),
            'check_out' => $target->copy()->addDays(3)->toDateString(),
        ])->assertOk()->assertJsonPath('data.source', 'admin')->assertJsonPath('data.status', Reschedule::STATUS_DISETUJUI);

        $this->assertSame(3, $booking->fresh()->nights);

        $this->getJson("/api/bookings/{$booking->id}")->assertOk()
            ->assertJsonPath('data.reschedule.new_check_in', $target->toDateString());
    }

    public function test_admin_cannot_reschedule_a_cancelled_booking(): void
    {
        $booking = $this->guestBooking();
        $this->asAdmin();
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => Booking::STATUS_DIBATALKAN])->assertOk();

        $this->postJson("/api/bookings/{$booking->id}/reschedule", [
            'check_in' => $this->monday(6)->toDateString(),
            'check_out' => $this->monday(6)->addDays(2)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_customer_role_cannot_use_admin_reschedule_endpoints(): void
    {
        $booking = $this->guestBooking();
        $this->asGuest();

        $this->getJson('/api/reschedules')->assertForbidden();
        $this->postJson("/api/bookings/{$booking->id}/reschedule", [
            'check_in' => $this->monday(6)->toDateString(),
            'check_out' => $this->monday(6)->addDays(2)->toDateString(),
        ])->assertForbidden();
    }
}
