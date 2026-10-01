<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Survey;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Manajemen Survey (B4) — papan admin.
 *
 * Jalur admin berbeda bentuk dari jalur customer (A5): belum tentu ada
 * booking, item, atau tamu terdaftar. Aturan jamnya SAMA (CR-07): jendela
 * villa, tidak bertumpuk per villa, H-1 — kecuali jeda H+2.
 */
class SurveyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function villa(): Category
    {
        return Category::where('slug', 'villa-de-corrinna')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->villa()->id,
            'guest_name' => 'Rina Pratiwi',
            'scheduled_date' => Carbon::today()->addDays(10)->toDateString(),
            'scheduled_time' => '10:00',
            'scheduled_end_time' => '11:00',
        ], $overrides);
    }

    // ------------------------------------------------------------ menjadwal

    public function test_admin_can_schedule_a_survey_without_a_booking(): void
    {
        $response = $this->postJson('/api/surveys', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.guest_name', 'Rina Pratiwi')
            ->assertJsonPath('data.villa', 'Villa De Corrinna')
            ->assertJsonPath('data.status', Survey::STATUS_TERJADWAL)
            ->assertJsonPath('data.scheduled_time', '10:00');

        // Calon tamu yang menghubungi lewat WhatsApp belum punya apa pun.
        $survey = Survey::firstOrFail();
        $this->assertNull($survey->booking_id);
        $this->assertNull($survey->item_id);
        $this->assertNull($survey->guest_id);
    }

    /** Rentang jam di villa yang sama tidak boleh bertumpuk (1 survey per villa per waktu). */
    public function test_overlapping_time_in_the_same_villa_is_rejected(): void
    {
        $this->postJson('/api/surveys', $this->payload())->assertCreated();

        $this->postJson('/api/surveys', $this->payload([
            'guest_name' => 'Budi', 'scheduled_time' => '10:30', 'scheduled_end_time' => '12:00',
        ]))->assertStatus(422);

        // Bersambung (mulai tepat saat yang lain selesai) boleh.
        $this->postJson('/api/surveys', $this->payload([
            'guest_name' => 'Budi', 'scheduled_time' => '11:00', 'scheduled_end_time' => '12:00',
        ]))->assertCreated();
    }

    public function test_same_time_in_another_villa_is_allowed(): void
    {
        $this->postJson('/api/surveys', $this->payload())->assertCreated();

        $other = Category::where('slug', 'villa-cendana-wangi')->firstOrFail();

        $this->postJson('/api/surveys', $this->payload(['category_id' => $other->id]))->assertCreated();
    }

    public function test_cancelled_survey_frees_its_time(): void
    {
        $id = $this->postJson('/api/surveys', $this->payload())->json('data.id');
        $this->patchJson("/api/surveys/{$id}", ['status' => Survey::STATUS_DIBATALKAN])->assertOk();

        $this->postJson('/api/surveys', $this->payload(['guest_name' => 'Budi']))->assertCreated();
    }

    public function test_empty_villa_allows_seven_to_eight(): void
    {
        $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '06:30', 'scheduled_end_time' => '08:00']))
            ->assertStatus(422);
        $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '19:00', 'scheduled_end_time' => '20:30']))
            ->assertStatus(422);
        $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '07:00', 'scheduled_end_time' => '08:00']))
            ->assertCreated();
        $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '19:00', 'scheduled_end_time' => '20:00']))
            ->assertCreated();
    }

    /** Villa ada tamu -> hanya 12:00–14:00; hari check-in & check-out ikut dihitung. */
    public function test_occupied_villa_only_allows_noon_window_including_checkin_and_checkout_days(): void
    {
        $checkIn = Carbon::today()->addDays(10);

        $this->postJson('/api/bookings', [
            'item_id' => \App\Models\Item::where('name', 'Kamar Superior')->firstOrFail()->id,
            'guest_name' => 'Tamu Menginap',
            'guest_phone' => '081200000099',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
            'pax' => 10,
        ])->assertCreated();

        foreach ([0, 1, 2] as $offset) {
            $date = $checkIn->copy()->addDays($offset)->toDateString();

            $this->postJson('/api/surveys', $this->payload(['scheduled_date' => $date]))
                ->assertStatus(422);
            $this->postJson('/api/surveys', $this->payload([
                'scheduled_date' => $date, 'scheduled_time' => '12:00', 'scheduled_end_time' => '14:00',
            ]))->assertCreated();
        }

        // Sehari setelah check-out villa kosong lagi.
        $this->postJson('/api/surveys', $this->payload([
            'scheduled_date' => $checkIn->copy()->addDays(3)->toDateString(),
        ]))->assertCreated();
    }

    public function test_end_time_must_follow_start_time(): void
    {
        $this->postJson('/api/surveys', $this->payload(['scheduled_end_time' => '09:00']))
            ->assertStatus(422);
    }

    /** H-1 ditegakkan bila rencana check-in diketahui. */
    public function test_schedule_past_the_deadline_is_rejected(): void
    {
        $checkIn = Carbon::today()->addDays(10);

        $this->postJson('/api/surveys', $this->payload([
            'planned_check_in' => $checkIn->toDateString(),
            // Hari check-in sendiri: melewati batas H-1.
            'scheduled_date' => $checkIn->toDateString(),
        ]))->assertStatus(422);

        $this->postJson('/api/surveys', $this->payload([
            'planned_check_in' => $checkIn->toDateString(),
            'scheduled_date' => $checkIn->copy()->subDay()->toDateString(),
        ]))->assertCreated();
    }

    /**
     * Tanpa rencana check-in tidak ada yang bisa dihitung mundur — calon tamu
     * yang menyurvei sebelum punya tanggal pasti tidak boleh diblokir.
     */
    public function test_schedule_without_planned_check_in_is_allowed(): void
    {
        $this->postJson('/api/surveys', $this->payload([
            'scheduled_date' => Carbon::today()->addDays(90)->toDateString(),
        ]))->assertCreated();
    }

    public function test_schedule_requires_a_villa_and_guest_name(): void
    {
        $this->postJson('/api/surveys', ['scheduled_date' => '2027-01-10', 'scheduled_time' => '10:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'guest_name']);
    }

    /**
     * `ExistsInTenant`, bukan `exists:` bawaan: villa milik klien lain harus
     * ditolak, bukan diterima lalu tersimpan menunjuk induk tenant lain.
     */
    public function test_villa_of_another_tenant_is_rejected(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);

        // forceCreate + tanpa scope tenant: pola yang sama dipakai
        // CrossTenantWriteTest untuk menanam baris milik klien lain.
        $foreign = Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Villa Klien Lain',
            'slug' => 'villa-klien-lain',
            'status' => 'Aktif',
        ]);

        $this->postJson('/api/surveys', $this->payload(['category_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        $this->assertSame(0, Survey::count());
    }

    // ---------------------------------------------------------- daftar & ubah

    public function test_list_returns_pic_options_for_the_dropdown(): void
    {
        $this->postJson('/api/surveys', $this->payload())->assertCreated();

        $response = $this->getJson('/api/surveys');

        $response->assertOk()->assertJsonCount(1, 'data');

        // Role Admin sengaja tidak punya `users:index`, jadi kandidat PIC
        // harus datang bersama daftar ini — bukan lewat panggilan kedua.
        $this->assertNotEmpty($response->json('pic_options'));
    }

    public function test_list_can_be_filtered_by_status_and_searched(): void
    {
        $this->postJson('/api/surveys', $this->payload(['guest_name' => 'Rina Pratiwi']))->assertCreated();
        $this->postJson('/api/surveys', $this->payload([
            'guest_name' => 'Budi Santoso',
            'scheduled_time' => '13:00',
            'scheduled_end_time' => '14:00',
        ]))->assertCreated();

        $this->assertCount(1, $this->getJson('/api/surveys?q=budi')->json('data'));
        $this->assertCount(2, $this->getJson('/api/surveys?status='.Survey::STATUS_TERJADWAL)->json('data'));
        $this->assertCount(0, $this->getJson('/api/surveys?status='.Survey::STATUS_SELESAI)->json('data'));
    }

    public function test_pic_can_be_assigned(): void
    {
        $pic = User::where('email', 'admin@decorinna.test')->firstOrFail();

        $this->postJson('/api/surveys', $this->payload(['pic_user_id' => $pic->id]))
            ->assertCreated()
            ->assertJsonPath('data.pic.name', $pic->name);
    }

    /**
     * Menulis laporan berarti kunjungannya sudah terjadi — statusnya ikut
     * `Selesai` tanpa admin harus mengubahnya terpisah, supaya tidak ada baris
     * yang laporannya ada tapi statusnya menggantung.
     */
    public function test_writing_a_report_completes_the_survey(): void
    {
        $id = $this->postJson('/api/surveys', $this->payload())->json('data.id');

        $this->patchJson("/api/surveys/{$id}", ['report' => 'Tamu tertarik paket BBQ.'])
            ->assertOk()
            ->assertJsonPath('data.status', Survey::STATUS_SELESAI)
            ->assertJsonPath('data.report', 'Tamu tertarik paket BBQ.');
    }

    public function test_status_can_be_set_without_a_report(): void
    {
        $id = $this->postJson('/api/surveys', $this->payload())->json('data.id');

        $this->patchJson("/api/surveys/{$id}", ['status' => Survey::STATUS_MENUNGGU_LAPORAN])
            ->assertOk()
            ->assertJsonPath('data.status', Survey::STATUS_MENUNGGU_LAPORAN);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $id = $this->postJson('/api/surveys', $this->payload())->json('data.id');

        $this->patchJson("/api/surveys/{$id}", ['status' => 'Batal Saja'])->assertStatus(422);
    }

    /** Pindah jadwal diperiksa ulang terhadap survey LAIN, bukan dirinya sendiri. */
    public function test_rescheduling_checks_conflicts_excluding_itself(): void
    {
        $a = $this->postJson('/api/surveys', $this->payload())->json('data.id');
        $b = $this->postJson('/api/surveys', $this->payload([
            'guest_name' => 'Budi', 'scheduled_time' => '13:00', 'scheduled_end_time' => '14:00',
        ]))->json('data.id');

        $this->patchJson("/api/surveys/{$b}", ['scheduled_time' => '10:30'])->assertStatus(422);

        $this->patchJson("/api/surveys/{$a}", ['scheduled_time' => '10:30', 'scheduled_end_time' => '11:30'])
            ->assertOk()
            ->assertJsonPath('data.scheduled_end_time', '11:30');
    }

    public function test_rescheduling_past_the_deadline_is_rejected(): void
    {
        $checkIn = Carbon::today()->addDays(20);

        $id = $this->postJson('/api/surveys', $this->payload([
            'planned_check_in' => $checkIn->toDateString(),
            'scheduled_date' => Carbon::today()->addDays(5)->toDateString(),
        ]))->json('data.id');

        $this->patchJson("/api/surveys/{$id}", [
            'scheduled_date' => $checkIn->toDateString(),
        ])->assertStatus(422);
    }

    // --------------------------------------------------------------- akses

    public function test_customer_cannot_touch_surveys(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/surveys')->assertStatus(403);
        $this->postJson('/api/surveys', $this->payload())->assertStatus(403);
    }

    public function test_surveys_need_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/surveys')->assertStatus(401);
    }
}
