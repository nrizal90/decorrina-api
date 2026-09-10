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
 * booking, item, atau tamu terdaftar, dan jamnya bebas. Yang tidak boleh
 * berbeda adalah KUOTA-nya — keduanya memakai tim yang sama.
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

    /**
     * Jam bebas milik admin tetap dipetakan ke sesi baku, supaya jadwal ini
     * ikut memakan kuota slot yang ditawarkan ke customer. Tanpa ini, layar
     * customer masih menawarkan slot yang timnya sudah terpakai.
     */
    public function test_time_within_a_session_window_consumes_that_session(): void
    {
        // Tanggal paling awal yang ditawarkan (H+2). HARUS di dalam jendela
        // slot yang ditawarkan — memakai tanggal jauh membuat test ini lolos
        // semu, karena slotnya memang tidak muncul akibat batas jumlah.
        $date = Carbon::today()->addDays(2)->toDateString();

        $this->postJson('/api/surveys', $this->payload([
            'scheduled_date' => $date,
            'scheduled_time' => '09:30',
        ]))->assertCreated();

        $this->assertSame('Pagi', Survey::firstOrFail()->session);

        $slots = collect($this->getJson('/api/villas/villa-de-corrinna/survey-slots?check_in='
            .Carbon::today()->addDays(40)->toDateString())->json('data.slots'));

        // Pagi hilang karena kuotanya terpakai...
        $this->assertFalse($slots->contains(
            fn ($s) => $s['date'] === $date && $s['session'] === 'Pagi'
        ));

        // ...sementara Siang di tanggal yang sama tetap ada. Ini yang
        // membuktikan penyebabnya kuota, bukan tanggalnya di luar jendela.
        $this->assertTrue($slots->contains(
            fn ($s) => $s['date'] === $date && $s['session'] === 'Siang'
        ));
    }

    /** Kunjungan di luar jam operasional tidak menutup slot mana pun. */
    public function test_time_outside_every_session_consumes_no_slot(): void
    {
        $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '17:00']))
            ->assertCreated();

        $this->assertNull(Survey::firstOrFail()->session);
    }

    /** H-7 ditegakkan bila rencana check-in diketahui. */
    public function test_schedule_past_the_deadline_is_rejected(): void
    {
        $checkIn = Carbon::today()->addDays(10);

        $this->postJson('/api/surveys', $this->payload([
            'planned_check_in' => $checkIn->toDateString(),
            // H-1: jauh melewati batas.
            'scheduled_date' => $checkIn->copy()->subDay()->toDateString(),
        ]))->assertStatus(422);
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

    /** Memindahkan jadwal ikut memperbarui sesi, kalau tidak kuota jadi salah. */
    public function test_rescheduling_updates_the_session(): void
    {
        $id = $this->postJson('/api/surveys', $this->payload(['scheduled_time' => '10:00']))->json('data.id');

        $this->patchJson("/api/surveys/{$id}", ['scheduled_time' => '13:30'])->assertOk();

        $this->assertSame('Siang', Survey::findOrFail($id)->session);
    }

    public function test_rescheduling_past_the_deadline_is_rejected(): void
    {
        $checkIn = Carbon::today()->addDays(20);

        $id = $this->postJson('/api/surveys', $this->payload([
            'planned_check_in' => $checkIn->toDateString(),
            'scheduled_date' => Carbon::today()->addDays(5)->toDateString(),
        ]))->json('data.id');

        $this->patchJson("/api/surveys/{$id}", [
            'scheduled_date' => $checkIn->copy()->subDay()->toDateString(),
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
