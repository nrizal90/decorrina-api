<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Override tenant oleh superadmin-azatech lewat header `X-Tenant`.
 *
 * Akun vendor `tenant_id`-nya NULL, sementara seluruh tabel domain punya
 * `tenant_id NOT NULL` — tanpa mekanisme ini ia tak bisa membuat master data
 * atas nama klien mana pun.
 */
class AzatechTenantOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function azatech(): User
    {
        return User::where('email', 'superadmin@azatech.id')->firstOrFail();
    }

    private function klien(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    private function tenant(): Tenant
    {
        return Tenant::where('slug', 'decorinna')->firstOrFail();
    }

    // ------------------------------------------------------------------ tulis

    public function test_azatech_can_create_master_data_for_a_named_tenant(): void
    {
        Sanctum::actingAs($this->azatech());

        $response = $this->withHeader('X-Tenant', 'decorinna')
            ->postJson('/api/admin/categories', [
                'name' => 'Villa Titipan Vendor',
                'icon' => 'trees',
                'status' => 'Aktif',
            ]);

        $response->assertCreated();

        $created = Category::withoutGlobalScope('tenant')
            ->findOrFail($response->json('data.id'));

        // Inti pengujian: barisnya benar-benar menempel ke tenant yang disebut.
        $this->assertSame($this->tenant()->id, $created->tenant_id);
    }

    public function test_azatech_write_without_tenant_header_is_rejected_clearly(): void
    {
        Sanctum::actingAs($this->azatech());

        // Sebelum perbaikan ini balasannya 500 dari pelanggaran NOT NULL.
        $this->postJson('/api/admin/categories', ['name' => 'Tanpa Tenant'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sertakan header X-Tenant untuk menentukan klien yang dituju.');

        $this->assertDatabaseMissing('categories', ['name' => 'Tanpa Tenant']);
    }

    public function test_azatech_write_to_unknown_tenant_is_rejected(): void
    {
        Sanctum::actingAs($this->azatech());

        $this->withHeader('X-Tenant', 'klien-tidak-ada')
            ->postJson('/api/admin/categories', ['name' => 'Salah Tenant'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------- baca

    public function test_azatech_read_is_scoped_when_tenant_header_is_sent(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Villa Tetangga',
            'slug' => 'villa-tetangga',
            'status' => 'Aktif',
        ]);

        Sanctum::actingAs($this->azatech());

        $names = collect(
            $this->withHeader('X-Tenant', 'decorinna')->getJson('/api/admin/categories')->json('data')
        )->pluck('name');

        $this->assertNotContains('Villa Tetangga', $names);
        $this->assertContains('Villa De Corrinna', $names);
    }

    public function test_azatech_read_without_header_still_spans_tenants(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Villa Tetangga',
            'slug' => 'villa-tetangga',
            'status' => 'Aktif',
        ]);

        Sanctum::actingAs($this->azatech());

        $names = collect($this->getJson('/api/admin/categories')->json('data'))->pluck('name');

        // Tanpa header, akun vendor tetap melihat lintas tenant seperti semula.
        $this->assertContains('Villa Tetangga', $names);
        $this->assertContains('Villa De Corrinna', $names);
    }

    public function test_user_created_by_azatech_belongs_to_the_active_tenant(): void
    {
        Sanctum::actingAs($this->azatech());

        // Form B13 tidak mengirim tenant_id — sebelum perbaikan, user lahir
        // dengan tenant_id NULL dan tak menjadi milik klien mana pun.
        $response = $this->withHeader('X-Tenant', 'decorinna')
            ->postJson('/api/users', [
                'name' => 'Staf Titipan',
                'email' => 'staf.titipan@decorinna.test',
                'password' => 'rahasia-sekali-123',
                'password_confirmation' => 'rahasia-sekali-123',
                'roles' => ['staff'],
            ]);

        $response->assertCreated();

        $created = User::withoutGlobalScope('tenant')->findOrFail($response->json('data.id'));

        $this->assertSame($this->tenant()->id, $created->tenant_id);
    }

    public function test_azatech_may_still_target_another_tenant_explicitly(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);

        Sanctum::actingAs($this->azatech());

        // tenant_id di body tetap menang atas header — dipakai bila vendor
        // membuatkan akun untuk klien lain tanpa berpindah konteks.
        $response = $this->withHeader('X-Tenant', 'decorinna')
            ->postJson('/api/users', [
                'name' => 'Staf Klien Lain',
                'email' => 'staf.lain@klien-lain.test',
                'password' => 'rahasia-sekali-123',
                'password_confirmation' => 'rahasia-sekali-123',
                'tenant_id' => $other->id,
                'roles' => ['staff'],
            ]);

        $created = User::withoutGlobalScope('tenant')->findOrFail($response->json('data.id'));

        $this->assertSame($other->id, $created->tenant_id);
    }

    // --------------------------------------------------------------- keamanan

    public function test_client_user_cannot_use_the_header_to_reach_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Villa Tetangga',
            'slug' => 'villa-tetangga',
            'status' => 'Aktif',
        ]);

        Sanctum::actingAs($this->klien());

        $names = collect(
            $this->withHeader('X-Tenant', 'klien-lain')->getJson('/api/admin/categories')->json('data')
        )->pluck('name');

        // Header diabaikan untuk user internal klien — tetap terkunci di tenantnya.
        $this->assertNotContains('Villa Tetangga', $names);
        $this->assertContains('Villa De Corrinna', $names);
    }

    public function test_client_user_creation_still_lands_in_their_own_tenant(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);

        Sanctum::actingAs($this->klien());

        $response = $this->withHeader('X-Tenant', 'klien-lain')
            ->postJson('/api/admin/categories', ['name' => 'Coba Bocor', 'status' => 'Aktif']);

        $response->assertCreated();

        $created = Category::withoutGlobalScope('tenant')->findOrFail($response->json('data.id'));

        $this->assertSame($this->klien()->tenant_id, $created->tenant_id);
        $this->assertNotSame($other->id, $created->tenant_id);
    }
}
