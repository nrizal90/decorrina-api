<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSettings;
use Database\Seeders\AuthRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengaturan tenant (Settings > Kebijakan Operasional): tersimpan di DB per
 * tenant, default dari config bila belum diatur.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function reschedule(array $overrides = []): array
    {
        return ['reschedule' => array_merge([
            'quota' => 2,
            'deadline_days' => 5,
            'allow_change_nights' => true,
            'allow_change_item' => false,
        ], $overrides)];
    }

    public function test_defaults_come_from_config_until_saved(): void
    {
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.reschedule.quota', 1)
            ->assertJsonPath('data.reschedule.deadline_days', 3)
            ->assertJsonPath('data.reschedule.allow_change_nights', false);
    }

    public function test_admin_can_save_and_read_back(): void
    {
        $this->putJson('/api/settings', $this->reschedule())->assertOk()
            ->assertJsonPath('data.reschedule.quota', 2);

        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.reschedule.deadline_days', 5)
            ->assertJsonPath('data.reschedule.allow_change_nights', true);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->putJson('/api/settings', $this->reschedule(['quota' => -1]))->assertStatus(422)
            ->assertJsonValidationErrors('reschedule.quota');
        $this->putJson('/api/settings', $this->reschedule(['allow_change_item' => 'mungkin']))->assertStatus(422);
    }

    public function test_settings_are_per_tenant(): void
    {
        $this->putJson('/api/settings', $this->reschedule())->assertOk();

        $other = Tenant::create(['name' => 'Lain', 'slug' => 'lain', 'status' => 'Aktif']);

        $this->assertSame(1, TenantSettings::get('reschedule.quota', $other->id));
    }

    public function test_staff_cannot_change_settings(): void
    {
        $staff = User::factory()->create(['tenant_id' => Tenant::where('slug', 'decorinna')->value('id')]);
        $staff->assignRole('staff');
        Sanctum::actingAs($staff);

        $this->putJson('/api/settings', $this->reschedule())->assertForbidden();
    }
}
