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
 * Kode Benefit & Akses Kawasan (B6, Fase 6).
 *
 * Yang dijaga: kode lahir saat booking ditandai DP/Lunas (belum ada payment
 * gateway), tidak pernah berganti, dan panel hanya berisi booking berkode.
 */
class BenefitCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function book(): Booking
    {
        $item = Item::where('name', 'Kamar Superior')->firstOrFail();
        $checkIn = Carbon::today()->addDays(20 + random_int(0, 300));

        $response = $this->postJson('/api/bookings', [
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '0812'.random_int(100000, 999999),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDay()->toDateString(),
            'pax' => $item->cap_min,
        ])->assertCreated();

        return Booking::findOrFail($response->json('data.id'));
    }

    private function setStatus(Booking $booking, string $status): void
    {
        $this->patchJson("/api/bookings/{$booking->id}/status", ['status' => $status])->assertOk();
    }

    public function test_unpaid_booking_has_no_access_code(): void
    {
        $booking = $this->book();

        $this->assertNull($booking->kode_akses_kawasan);
        $this->getJson('/api/admin/benefit-codes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dp_generates_a_code_that_survives_later_payment(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_DP);
        $code = $booking->fresh()->kode_akses_kawasan;

        $this->assertMatchesRegularExpression('/^GNG-[A-Z2-9]{6}$/', $code);

        $this->setStatus($booking, Booking::STATUS_LUNAS);
        $this->assertSame($code, $booking->fresh()->kode_akses_kawasan);

        $this->getJson('/api/admin/benefit-codes')
            ->assertOk()
            ->assertJsonPath('data.0.kode_akses_kawasan', $code)
            ->assertJsonPath('data.0.benefit_status', Booking::BENEFIT_BELUM);
    }

    public function test_cancelled_booking_leaves_the_panel_and_cannot_be_resent(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_LUNAS);
        $this->setStatus($booking, Booking::STATUS_DIBATALKAN);

        $this->getJson('/api/admin/benefit-codes')->assertJsonCount(0, 'data');
        $this->postJson("/api/admin/benefit-codes/{$booking->id}/resend")->assertStatus(422);
    }

    public function test_mark_shown_and_undo(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_LUNAS);

        $this->patchJson("/api/admin/benefit-codes/{$booking->id}", ['shown' => true])
            ->assertOk()
            ->assertJsonPath('data.benefit_status', Booking::BENEFIT_SUDAH);

        $this->getJson('/api/admin/benefit-codes?benefit_status=Belum Digunakan')->assertJsonCount(0, 'data');

        $this->patchJson("/api/admin/benefit-codes/{$booking->id}", ['shown' => false])
            ->assertJsonPath('data.benefit_status', Booking::BENEFIT_BELUM);
    }

    public function test_cannot_mark_booking_without_code(): void
    {
        $booking = $this->book();

        $this->patchJson("/api/admin/benefit-codes/{$booking->id}", ['shown' => true])->assertStatus(422);
    }

    public function test_resend_returns_a_whatsapp_link_in_international_format(): void
    {
        $booking = $this->book();
        $this->setStatus($booking, Booking::STATUS_DP);
        $booking->refresh();

        $url = $this->postJson("/api/admin/benefit-codes/{$booking->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp_link')
            ->json('data.url');

        $this->assertStringStartsWith('https://wa.me/62812', $url);
        $this->assertStringContainsString($booking->kode_akses_kawasan, urldecode($url));
    }
}
