<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
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
 * Survey lokasi (A5) — CR-07, klarifikasi klien 27 Sep 2026.
 *
 * Tamu memilih tanggal lalu jam mulai & selesai. Jendela jam per villa
 * (07:00–20:00 kosong, 12:00–14:00 ada tamu), tidak bertumpuk per villa,
 * paling lambat H-1 check-in, paling cepat H+2. Endpoint jadwal dan
 * pemeriksaan saat booking memakai aturan yang sama.
 */
class SurveySchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function item(): Item
    {
        return Category::where('slug', 'villa-de-corrinna')
            ->firstOrFail()
            ->activeItems()
            ->orderBy('id')
            ->firstOrFail();
    }

    /** Check-in yang cukup jauh agar selalu ada tanggal survey yang sah. */
    private function checkIn(): string
    {
        return Carbon::today()->addDays(30)->toDateString();
    }

    private function surveyDate(): string
    {
        return Carbon::today()->addDays(5)->toDateString();
    }

    /** @return array<string, mixed> */
    private function schedule(array $query = []): array
    {
        return $this->getJson('/api/villas/villa-de-corrinna/survey-schedule?'.http_build_query(
            $query + ['check_in' => $this->checkIn()]
        ))->assertOk()->json('data');
    }

    // ------------------------------------------------------------ endpoint

    public function test_schedule_returns_date_bounds_h_plus_2_to_h_minus_1(): void
    {
        $data = $this->schedule();

        $this->assertSame(Carbon::today()->addDays(2)->toDateString(), $data['earliest']);
        $this->assertSame(Carbon::parse($this->checkIn())->subDay()->toDateString(), $data['deadline']);
        $this->assertNull($data['day']);
    }

    public function test_empty_villa_day_offers_seven_to_eight(): void
    {
        $day = $this->schedule(['date' => $this->surveyDate()])['day'];

        $this->assertFalse($day['occupied']);
        $this->assertSame(['start' => '07:00', 'end' => '20:00'], $day['window']);
        $this->assertSame([], $day['taken']);
    }

    public function test_occupied_villa_day_offers_noon_window(): void
    {
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
        $this->postJson('/api/bookings', $this->booking([
            'guest_phone' => '081200000050',
            'check_in' => $this->surveyDate(),
            'check_out' => Carbon::parse($this->surveyDate())->addDay()->toDateString(),
        ]))->assertCreated();

        // Hari check-out juga dihitung ada tamu.
        $checkoutDay = Carbon::parse($this->surveyDate())->addDay()->toDateString();
        $day = $this->schedule(['date' => $checkoutDay])['day'];

        $this->assertTrue($day['occupied']);
        $this->assertSame(['start' => '12:00', 'end' => '14:00'], $day['window']);
    }

    public function test_schedule_reports_whether_the_item_needs_a_survey(): void
    {
        $item = $this->item();

        $this->assertSame((bool) $item->requires_survey, $this->schedule(['item_id' => $item->id])['requires_survey']);
    }

    public function test_schedule_rejects_an_item_from_another_villa(): void
    {
        $this->getJson('/api/villas/villa-cendana-wangi/survey-schedule?'.http_build_query([
            'check_in' => $this->checkIn(),
            'item_id' => $this->item()->id,
        ]))->assertStatus(404);
    }

    public function test_schedule_requires_a_check_in_date(): void
    {
        $this->getJson('/api/villas/villa-de-corrinna/survey-schedule')->assertStatus(422);
    }

    // ------------------------------------------------- survey saat booking

    private function booking(array $overrides = []): array
    {
        $item = $this->item();
        $checkIn = $this->checkIn();

        return array_merge([
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '081200000001',
            'check_in' => $checkIn,
            'check_out' => Carbon::parse($checkIn)->addDays(2)->toDateString(),
            'pax' => $item->cap_min,
        ], $overrides);
    }

    private function book(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

        return $this->postJson('/api/bookings', $this->booking($overrides));
    }

    private function survey(string $start = '09:00', string $end = '10:00', ?string $date = null): array
    {
        return ['date' => $date ?? $this->surveyDate(), 'start_time' => $start, 'end_time' => $end];
    }

    public function test_booking_can_carry_a_survey_schedule(): void
    {
        $response = $this->book(['survey' => $this->survey() + ['notes' => 'datang berempat']]);

        $response->assertCreated();

        $survey = Survey::firstOrFail();

        $this->assertSame($this->surveyDate(), $survey->scheduled_date->toDateString());
        $this->assertSame('09:00', substr((string) $survey->scheduled_time, 0, 5));
        $this->assertSame('10:00', substr((string) $survey->scheduled_end_time, 0, 5));
        $this->assertSame(Survey::STATUS_TERJADWAL, $survey->status);
        $this->assertSame($response->json('data.id'), $survey->booking_id);
        $this->assertSame($this->item()->category_id, $survey->category_id);
    }

    public function test_booking_without_a_survey_creates_none(): void
    {
        $this->book()->assertCreated();

        $this->assertSame(0, Survey::count());
    }

    /** Jam terisi muncul di endpoint DAN ditolak saat dikirim. */
    public function test_taken_time_is_reported_and_overlap_rejected(): void
    {
        $this->book(['survey' => $this->survey('09:00', '10:00')])->assertCreated();

        $this->assertSame(
            [['start' => '09:00', 'end' => '10:00']],
            $this->schedule(['date' => $this->surveyDate()])['day']['taken'],
        );

        $next = [
            'guest_phone' => '081200000002',
            'check_in' => Carbon::parse($this->checkIn())->addDays(5)->toDateString(),
            'check_out' => Carbon::parse($this->checkIn())->addDays(7)->toDateString(),
        ];

        $this->book($next + ['survey' => $this->survey('09:30', '10:30')])->assertStatus(422);
        $this->book($next + ['survey' => $this->survey('10:00', '11:00')])->assertCreated();
    }

    public function test_time_outside_the_window_is_rejected(): void
    {
        $this->book(['survey' => $this->survey('06:00', '07:30')])->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    public function test_survey_on_check_in_day_is_rejected_but_h_minus_1_is_allowed(): void
    {
        $this->book(['survey' => $this->survey(date: $this->checkIn())])->assertStatus(422);

        $this->book(['survey' => $this->survey(date: Carbon::parse($this->checkIn())->subDay()->toDateString())])
            ->assertCreated();
    }

    public function test_end_time_must_follow_start_time(): void
    {
        $this->book(['survey' => $this->survey('10:00', '09:00')])->assertStatus(422);
    }

    /** Booking batal -> survey yang belum terjadi ikut batal & jamnya terbuka lagi. */
    public function test_cancelling_a_booking_cancels_its_pending_survey(): void
    {
        $id = $this->book(['survey' => $this->survey('09:00', '10:00')])->json('data.id');

        $this->patchJson("/api/bookings/{$id}/status", ['status' => Booking::STATUS_DIBATALKAN])->assertOk();

        $this->assertSame(Survey::STATUS_DIBATALKAN, Survey::firstOrFail()->status);
        $this->assertSame([], $this->schedule(['date' => $this->surveyDate()])['day']['taken']);
    }

    public function test_completed_survey_is_kept_when_its_booking_is_cancelled(): void
    {
        $id = $this->book(['survey' => $this->survey()])->json('data.id');
        Survey::firstOrFail()->update(['status' => Survey::STATUS_SELESAI]);

        $this->patchJson("/api/bookings/{$id}/status", ['status' => Booking::STATUS_DIBATALKAN])->assertOk();

        $this->assertSame(Survey::STATUS_SELESAI, Survey::firstOrFail()->status);
    }

    public function test_expired_unpaid_booking_cancels_its_survey(): void
    {
        $this->withHeader('X-Tenant', 'decorinna')->postJson('/api/public/bookings', $this->booking([
            'survey' => $this->survey(),
        ]))->assertCreated();

        Booking::query()->update(['created_at' => now()->subHours(config('booking.pending_expiry_hours') + 1)]);
        $this->artisan('bookings:expire-pending');

        $this->assertSame(Booking::STATUS_DIBATALKAN, Booking::firstOrFail()->status);
        $this->assertSame(Survey::STATUS_DIBATALKAN, Survey::firstOrFail()->status);
    }

    public function test_survey_is_not_created_when_the_booking_fails(): void
    {
        // pax di luar kapasitas -> booking ditolak; survey tidak boleh tertinggal.
        $this->book(['pax' => 999, 'survey' => $this->survey()])->assertStatus(422);

        $this->assertSame(0, Survey::count());
        $this->assertSame(0, Booking::count());
    }
}
