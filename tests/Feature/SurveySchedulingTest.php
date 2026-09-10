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
 * Survey lokasi (A5, Fase 5).
 *
 * Slot TIDAK ditabelkan — dibangkitkan dari config/survey.php lalu dikurangi
 * yang sudah dipesan. Test di sini menjaga tiga aturan tanggalnya (H+2, H-7,
 * kuota per sesi) dan memastikan daftar yang dilihat pengunjung sama dengan
 * yang diterima saat booking dibuat.
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

    /** Check-in yang cukup jauh agar selalu ada slot yang sah. */
    private function checkIn(): string
    {
        return Carbon::today()->addDays(30)->toDateString();
    }

    /** @return array<string, mixed> */
    private function slots(?string $checkIn = null, array $extra = []): array
    {
        $response = $this->getJson('/api/villas/villa-de-corrinna/survey-slots?'.http_build_query(
            ['check_in' => $checkIn ?? $this->checkIn()] + $extra
        ));

        $response->assertOk();

        return $response->json('data');
    }

    // ---------------------------------------------------------------- slot

    public function test_slots_are_public(): void
    {
        $this->getJson('/api/villas/villa-de-corrinna/survey-slots?check_in='.$this->checkIn())
            ->assertOk();
    }

    /** Batasnya H-7 sebelum check-in, dan itu dikembalikan apa adanya. */
    public function test_deadline_is_seven_days_before_check_in(): void
    {
        $checkIn = $this->checkIn();

        $this->assertSame(
            Carbon::parse($checkIn)->subDays(7)->toDateString(),
            $this->slots($checkIn)['deadline'],
        );
    }

    public function test_offered_slots_respect_the_lead_time_and_deadline(): void
    {
        $data = $this->slots();

        $earliest = Carbon::today()->addDays(2);
        $deadline = Carbon::parse($data['deadline']);

        $this->assertNotEmpty($data['slots']);

        foreach ($data['slots'] as $slot) {
            $date = Carbon::parse($slot['date']);

            $this->assertTrue($date->greaterThanOrEqualTo($earliest), "{$slot['date']} terlalu cepat.");
            $this->assertTrue($date->lessThanOrEqualTo($deadline), "{$slot['date']} melewati deadline.");
        }
    }

    /**
     * Check-in yang terlalu dekat bukan error — daftarnya memang kosong, dan
     * `deadline` tetap dikirim supaya layar bisa menjelaskan alasannya.
     */
    public function test_check_in_too_soon_yields_no_slots(): void
    {
        $data = $this->slots(Carbon::today()->addDays(3)->toDateString());

        $this->assertSame([], $data['slots']);
        $this->assertNotNull($data['deadline']);
    }

    public function test_slots_report_whether_the_item_needs_a_survey(): void
    {
        $item = $this->item();

        $this->assertSame(
            (bool) $item->requires_survey,
            $this->slots(null, ['item_id' => $item->id])['requires_survey'],
        );
    }

    public function test_slots_reject_an_item_from_another_villa(): void
    {
        $this->getJson('/api/villas/villa-cendana-wangi/survey-slots?'.http_build_query([
            'check_in' => $this->checkIn(),
            'item_id' => $this->item()->id,
        ]))->assertStatus(404);
    }

    public function test_slots_require_a_check_in_date(): void
    {
        $this->getJson('/api/villas/villa-de-corrinna/survey-slots')->assertStatus(422);
    }

    // ------------------------------------------------- survey saat booking

    private function book(array $overrides = []): array
    {
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());

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

    public function test_booking_can_carry_a_survey_schedule(): void
    {
        $slot = $this->slots()['slots'][0];

        $response = $this->postJson('/api/bookings', $this->book([
            'survey' => [
                'date' => $slot['date'],
                'session' => $slot['session'],
                'notes' => 'mohon setelah jam 10 pagi',
            ],
        ]));

        $response->assertCreated();

        $survey = Survey::firstOrFail();

        $this->assertSame($slot['date'], $survey->scheduled_date->toDateString());
        $this->assertSame($slot['session'], $survey->session);
        $this->assertSame(Survey::STATUS_TERJADWAL, $survey->status);
        $this->assertSame($response->json('data.id'), $survey->booking_id);

        // Jalur customer memilih SESI, tapi papan admin menampilkan jam —
        // jadi jam mulai sesinya ikut disimpan, bukan dibiarkan kosong.
        $this->assertSame('09:00', substr((string) $survey->scheduled_time, 0, 5));
        $this->assertSame($this->item()->category_id, $survey->category_id);
        $this->assertNotNull($survey->guest_id);
    }

    public function test_booking_without_a_survey_creates_none(): void
    {
        $this->postJson('/api/bookings', $this->book())->assertCreated();

        $this->assertSame(0, Survey::count());
    }

    /**
     * Kuota per sesi habis -> slot hilang dari daftar DAN ditolak saat dikirim.
     * Daftar bisa basi beberapa menit setelah dilihat, jadi keduanya harus
     * memakai aturan yang sama.
     */
    public function test_a_taken_slot_disappears_and_is_rejected(): void
    {
        $slot = $this->slots()['slots'][0];
        $survey = ['date' => $slot['date'], 'session' => $slot['session']];

        $this->postJson('/api/bookings', $this->book(['survey' => $survey]))->assertCreated();

        $stillOffered = collect($this->slots()['slots'])
            ->contains(fn ($s) => $s['date'] === $slot['date'] && $s['session'] === $slot['session']);

        $this->assertFalse($stillOffered);

        $this->postJson('/api/bookings', $this->book([
            'guest_phone' => '081200000002',
            'check_in' => Carbon::parse($this->checkIn())->addDays(5)->toDateString(),
            'check_out' => Carbon::parse($this->checkIn())->addDays(7)->toDateString(),
            'survey' => $survey,
        ]))->assertStatus(422);
    }

    /** Survey yang dibatalkan mengembalikan slotnya, seperti booking. */
    public function test_cancelled_survey_frees_its_slot(): void
    {
        $slot = $this->slots()['slots'][0];

        $this->postJson('/api/bookings', $this->book([
            'survey' => ['date' => $slot['date'], 'session' => $slot['session']],
        ]))->assertCreated();

        Survey::firstOrFail()->update(['status' => Survey::STATUS_DIBATALKAN]);

        $offeredAgain = collect($this->slots()['slots'])
            ->contains(fn ($s) => $s['date'] === $slot['date'] && $s['session'] === $slot['session']);

        $this->assertTrue($offeredAgain);
    }

    public function test_survey_past_the_deadline_is_rejected(): void
    {
        $checkIn = $this->checkIn();

        $this->postJson('/api/bookings', $this->book([
            'survey' => [
                // Sehari sebelum check-in — jauh melewati batas H-7.
                'date' => Carbon::parse($checkIn)->subDay()->toDateString(),
                'session' => 'Pagi',
            ],
        ]))->assertStatus(422);
    }

    public function test_unknown_session_is_rejected(): void
    {
        $slot = $this->slots()['slots'][0];

        $this->postJson('/api/bookings', $this->book([
            'survey' => ['date' => $slot['date'], 'session' => 'Malam'],
        ]))->assertStatus(422);
    }

    public function test_survey_is_not_created_when_the_booking_fails(): void
    {
        $slot = $this->slots()['slots'][0];

        // pax di luar kapasitas -> booking ditolak; survey tidak boleh tertinggal.
        $this->postJson('/api/bookings', $this->book([
            'pax' => 999,
            'survey' => ['date' => $slot['date'], 'session' => $slot['session']],
        ]))->assertStatus(422);

        $this->assertSame(0, Survey::count());
        $this->assertSame(0, Booking::count());
    }
}
