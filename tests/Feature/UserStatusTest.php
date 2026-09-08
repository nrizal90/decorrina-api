<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Status akun aktif/nonaktif (B13). Nonaktif = akun ditahan, bukan dihapus:
 * tak bisa login dan token yang sudah terbit ikut dicabut, tapi barisnya tetap
 * ada agar jejaknya di modul lain tidak hilang.
 */
class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
    }

    private function klien(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    // ------------------------------------------------------------------ login

    public function test_existing_users_default_to_active(): void
    {
        $this->assertSame('Aktif', $this->klien()->status);
    }

    public function test_active_user_can_login(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@decorinna.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.status', 'Aktif');
    }

    public function test_inactive_user_cannot_login_even_with_correct_password(): void
    {
        $this->klien()->update(['status' => 'Nonaktif']);

        // 403, bukan 401 — kredensialnya benar, aksesnya yang ditahan.
        $this->postJson('/api/auth/login', [
            'email' => 'admin@decorinna.test',
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun Anda nonaktif. Hubungi admin.');
    }

    public function test_inactive_user_gets_no_token(): void
    {
        $user = $this->klien();
        $user->update(['status' => 'Nonaktif']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@decorinna.test',
            'password' => 'password',
        ]);

        $this->assertSame(0, $user->tokens()->count());
    }

    // --------------------------------------------------------------- resource

    public function test_status_is_exposed_in_user_payload(): void
    {
        Sanctum::actingAs($this->klien());

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.status', 'Aktif');
    }

    // ------------------------------------------------------------------- CRUD

    public function test_created_user_defaults_to_active_when_status_omitted(): void
    {
        Sanctum::actingAs($this->klien());

        $this->postJson('/api/users', [
            'name' => 'Staf Baru',
            'email' => 'staf.baru@decorinna.test',
            'password' => 'rahasia-sekali-123',
            'password_confirmation' => 'rahasia-sekali-123',
            'roles' => ['staff'],
        ])->assertCreated()->assertJsonPath('data.status', 'Aktif');
    }

    public function test_user_can_be_created_as_inactive(): void
    {
        Sanctum::actingAs($this->klien());

        $this->postJson('/api/users', [
            'name' => 'Belum Mulai',
            'email' => 'belum.mulai@decorinna.test',
            'password' => 'rahasia-sekali-123',
            'password_confirmation' => 'rahasia-sekali-123',
            'status' => 'Nonaktif',
            'roles' => ['staff'],
        ])->assertCreated()->assertJsonPath('data.status', 'Nonaktif');
    }

    public function test_status_can_be_toggled_via_update(): void
    {
        $actor = $this->klien();
        Sanctum::actingAs($actor);

        $target = User::create([
            'tenant_id' => $actor->tenant_id,
            'name' => 'Wayan Suarta',
            'email' => 'wayan@decorinna.test',
            'password' => 'rahasia-sekali-123',
        ]);
        $target->assignRole('staff');

        $this->patchJson("/api/users/{$target->id}", ['status' => 'Nonaktif'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Nonaktif');

        $this->patchJson("/api/users/{$target->id}", ['status' => 'Aktif'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Aktif');
    }

    public function test_deactivating_a_user_revokes_their_existing_tokens(): void
    {
        $actor = $this->klien();

        $target = User::create([
            'tenant_id' => $actor->tenant_id,
            'name' => 'Masih Login',
            'email' => 'masih.login@decorinna.test',
            'password' => 'rahasia-sekali-123',
        ]);
        $target->assignRole('staff');
        $target->createToken('spa');

        $this->assertSame(1, $target->tokens()->count());

        Sanctum::actingAs($actor);
        $this->patchJson("/api/users/{$target->id}", ['status' => 'Nonaktif'])->assertOk();

        // Tanpa ini, user nonaktif masih bisa memakai token lamanya.
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_invalid_status_is_rejected(): void
    {
        Sanctum::actingAs($this->klien());

        $this->postJson('/api/users', [
            'name' => 'Status Ngawur',
            'email' => 'ngawur@decorinna.test',
            'password' => 'rahasia-sekali-123',
            'password_confirmation' => 'rahasia-sekali-123',
            'status' => 'Active',
            'roles' => ['staff'],
        ])->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => $m !== null);
    }
}
