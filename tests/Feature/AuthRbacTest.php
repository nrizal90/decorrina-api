<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji fondasi RBAC multi-tenant Fase 1 (docs/06-rbac-decorina.md).
 */
class AuthRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
    }

    private function makeUser(?int $tenantId, string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $tenantId]);
        $user->assignRole($role);

        return $user;
    }

    private function klien(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    private function klienTenantId(): int
    {
        return $this->klien()->tenant_id;
    }

    private function azatech(): User
    {
        return User::where('email', 'superadmin@azatech.id')->firstOrFail();
    }

    public function test_register_creates_customer_account(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Tamu',
            'email' => 'tamu@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.tenant_id', null)
            ->assertJsonPath('data.user.roles', ['customer']);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * Status ikut di response registrasi, bukan null. Default kolom hanya
     * berlaku di DB — instance hasil create() tidak memuatnya kembali, dan FE
     * menyimpan payload ini apa adanya sebagai sesi yang sedang berjalan.
     */
    public function test_registered_account_is_active_in_the_response(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Tamu',
            'email' => 'tamu-aktif@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()->assertJsonPath('data.user.status', 'Aktif');

        // Dan akun itu memang bisa dipakai login — bukan sekadar benar di layar.
        $this->postJson('/api/auth/login', [
            'email' => 'tamu-aktif@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    /** Audit T-06: brute force satu akun tertahan setelah 5 percobaan/menit. */
    public function test_login_is_throttled_per_email(): void
    {
        $payload = ['email' => 'admin@decorinna.test', 'password' => 'salah'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(401);
        }
        $this->postJson('/api/auth/login', $payload)->assertStatus(429);

        // Email lain dari IP yang sama tidak ikut terkunci.
        $this->postJson('/api/auth/login', ['email' => 'lain@decorinna.test', 'password' => 'salah'])
            ->assertStatus(401);
    }

    public function test_register_is_throttled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/register', [])->assertStatus(422);
        }
        $this->postJson('/api/auth/register', [])->assertStatus(429);
    }

    public function test_login_returns_token_and_permissions(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@decorinna.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.roles', ['superadmin-klien'])
            ->assertJsonPath('data.user.tenant_id', $this->klienTenantId());

        $this->assertContains('users:index', $response->json('data.user.permissions'));
        $this->assertNotContains('system-config:manage', $response->json('data.user.permissions'));
    }

    public function test_me_returns_roles_permissions_tenant(): void
    {
        Sanctum::actingAs($this->klien());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['superadmin-klien'])
            ->assertJsonStructure(['data' => ['id', 'tenant_id', 'roles', 'permissions']]);
    }

    public function test_customer_cannot_list_users(): void
    {
        $customer = $this->makeUser(null, 'customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_klien_lists_only_own_tenant_users(): void
    {
        // Tenant lain + user-nya, tidak boleh terlihat oleh klien.
        $other = Tenant::create(['name' => 'Lain', 'slug' => 'lain', 'status' => 'Aktif']);
        $this->makeUser($other->id, 'admin');
        $this->makeUser($this->klienTenantId(), 'staff'); // tenant klien → terlihat

        Sanctum::actingAs($this->klien());

        $response = $this->getJson('/api/users')->assertOk();
        // Tenant klien punya: klien + staff = 2 (bukan user tenant lain / azatech).
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_klien_creates_staff_scoped_to_tenant(): void
    {
        Sanctum::actingAs($this->klien());

        $response = $this->postJson('/api/users', [
            'name' => 'Staff Baru',
            'email' => 'staffbaru@decorinna.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['staff'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->klienTenantId())
            ->assertJsonPath('data.roles', ['staff']);

        $this->assertNotContains('ledger:store', $response->json('data.permissions'));
    }

    public function test_azatech_has_bypass_and_cross_tenant_visibility(): void
    {
        Sanctum::actingAs($this->azatech());

        // Bypass: azatech punya semua permission (termasuk system-config).
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', null);

        // Lintas tenant: tanpa Global Scope, azatech melihat SEMUA user.
        $response = $this->getJson('/api/users')->assertOk();
        $this->assertSame(User::count(), $response->json('meta.total'));
    }

    public function test_role_sync_escalation_is_forbidden(): void
    {
        // Audit T-02: role global, jadi klien tak boleh menyunting SAMA SEKALI
        // (sebelumnya hanya system-config:manage & role vendor yang ditolak).
        $azatech = $this->azatech(); // ambil sebelum tenant scope terikat oleh request klien
        Sanctum::actingAs($this->klien());

        $this->putJson('/api/roles/staff/permissions', [
            'permissions' => ['reports:view'],
        ])->assertForbidden();
        $this->putJson('/api/roles/customer/permissions', [
            'permissions' => ['users:index'],
        ])->assertForbidden();
        $this->getJson('/api/roles')->assertOk(); // melihat matriks tetap boleh

        Sanctum::actingAs($azatech);

        // Role vendor immutable bahkan untuk azatech sendiri.
        $this->withHeader('X-Tenant', 'decorinna')->putJson('/api/roles/superadmin-azatech/permissions', [
            'permissions' => ['reports:view'],
        ])->assertForbidden();
        $this->withHeader('X-Tenant', 'decorinna')->putJson('/api/roles/staff/permissions', [
            'permissions' => ['reports:view'],
        ])->assertOk();
    }
}
