<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Single source of truth Role & Permission De'Corrinna.
 * Idempoten — aman dijalankan berulang. Lihat docs/06-rbac-decorina.md.
 *
 * Konvensi: guard 'web'; nama permission = nama route = FE guard string.
 * Role & permission DIDEFINISIKAN global (team_id NULL) agar dipakai semua
 * tenant; ASSIGNMENT role ke user yang disekat per tenant (model_has_roles).
 */
class AuthRolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        $descriptions = $this->descriptions();
        $allPermissions = array_keys($descriptions);

        // 1. Permission (statis, ditulis manual — TIDAK auto-scan route).
        foreach ($allPermissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => self::GUARD],
                ['description' => $descriptions[$permission]],
            );
        }

        // 2. Compose set permission per role dari blok dasar (array_diff/merge).
        $userRoleAdmin = $this->byPrefix($allPermissions, ['users:', 'roles:']);
        $systemConfig = ['system-config:manage'];

        // Admin = semua operasional (tanpa manajemen user/role & tanpa config sistem).
        $admin = array_values(array_diff($allPermissions, $userRoleAdmin, $systemConfig));
        // Staff = Admin minus Keuangan (ledger) & Settings.
        $staff = array_values(array_diff($admin, $this->byPrefix($admin, ['ledger:', 'settings:'])));
        // Superadmin Klien = semua KECUALI config sistem (termasuk kelola user/role).
        $superadminKlien = array_values(array_diff($allPermissions, $systemConfig));
        // Superadmin Azatech = semua (punya Gate bypass, ini untuk kelengkapan FE).
        $superadminAzatech = $allPermissions;
        // Stakeholder = read-only (index/show + reports:view).
        $stakeholder = array_values(array_filter(
            $allPermissions,
            fn (string $p) => str_ends_with($p, ':index')
                || str_ends_with($p, ':show')
                || $p === 'reports:view',
        ));
        // Customer = self-service (data sendiri, difilter ownership di Service).
        $customer = [
            'bookings:index', 'bookings:show', 'bookings:store', 'bookings:reschedule',
        ];

        $rolePermissions = [
            'superadmin-azatech' => $superadminAzatech,
            'superadmin-klien' => $superadminKlien,
            'admin' => $admin,
            'staff' => $staff,
            'stakeholder' => $stakeholder,
            'customer' => $customer,
        ];

        // 3. Buat role global + syncPermissions (otoritatif — menimpa).
        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => self::GUARD]);
            $role->syncPermissions($permissions);
        }

        // 4. User & tenant demo (idempoten).
        $this->seedDefaultUsers();
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $prefixes
     * @return array<int, string>
     */
    private function byPrefix(array $permissions, array $prefixes): array
    {
        return array_values(array_filter(
            $permissions,
            fn (string $p) => collect($prefixes)->contains(fn (string $prefix) => str_starts_with($p, $prefix)),
        ));
    }

    private function seedDefaultUsers(): void
    {
        // Superadmin Azatech (vendor) — tenant NULL, punya Gate::before bypass.
        $azatech = User::firstOrCreate(
            ['email' => env('SEED_AZATECH_EMAIL', 'superadmin@azatech.id')],
            [
                'name' => 'Superadmin Azatech',
                'tenant_id' => null,
                'password' => Hash::make(env('SEED_AZATECH_PASSWORD', 'password')),
            ],
        );
        $azatech->syncRoles(['superadmin-azatech']);

        // Tenant demo + Superadmin Klien-nya (agar isolasi data testable).
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'decorinna'],
            ['name' => "De'Corrinna", 'status' => 'Aktif'],
        );

        $klien = User::firstOrCreate(
            ['email' => env('SEED_KLIEN_EMAIL', 'admin@decorinna.test')],
            [
                'name' => 'Superadmin Klien',
                'tenant_id' => $tenant->id,
                'password' => Hash::make(env('SEED_KLIEN_PASSWORD', 'password')),
            ],
        );
        $klien->syncRoles(['superadmin-klien']);
    }

    /**
     * Peta permission => deskripsi Indonesia (untuk matriks B13).
     * INI daftar permission STATIS — sumber kebenaran tunggal.
     *
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        return [
            // User & Role (Fase 1)
            'users:index' => 'Lihat daftar user',
            'users:show' => 'Lihat detail user',
            'users:store' => 'Tambah user',
            'users:update' => 'Ubah user',
            'users:destroy' => 'Hapus user',
            'roles:index' => 'Lihat daftar role & permission',
            'roles:sync' => 'Ubah permission sebuah role',
            'system-config:manage' => 'Kelola konfigurasi sistem (Azatech only)',

            // Master Data (Fase 2)
            'items:index' => 'Lihat daftar item',
            'items:show' => 'Lihat detail item',
            'items:store' => 'Tambah item',
            'items:update' => 'Ubah item',
            'items:destroy' => 'Hapus item',
            'categories:index' => 'Lihat daftar kategori',
            'categories:store' => 'Tambah kategori',
            'categories:update' => 'Ubah kategori',
            'categories:destroy' => 'Hapus kategori',
            'facilities:index' => 'Lihat daftar fasilitas',
            'facilities:store' => 'Tambah fasilitas',
            'facilities:update' => 'Ubah fasilitas',
            'facilities:destroy' => 'Hapus fasilitas',
            'addons:index' => 'Lihat daftar add-on',
            'addons:store' => 'Tambah add-on',
            'addons:update' => 'Ubah add-on',
            'addons:destroy' => 'Hapus add-on',

            // Booking (Fase 3)
            'bookings:index' => 'Lihat daftar booking',
            'bookings:show' => 'Lihat detail booking',
            'bookings:store' => 'Buat booking',
            'bookings:update-status' => 'Ubah status booking',
            'bookings:reschedule' => 'Ajukan reschedule booking',

            // Payment & Invoice (Fase 4)
            'payments:index' => 'Lihat daftar pembayaran',
            'invoices:index' => 'Lihat daftar invoice',
            'invoices:show' => 'Lihat detail invoice',

            // Survey (Fase 5)
            'surveys:index' => 'Lihat daftar survey',
            'surveys:store' => 'Jadwalkan survey',
            'surveys:update' => 'Ubah survey',

            // Benefit (Fase 6)
            'benefits:index' => 'Lihat panel benefit',
            'benefits:resend' => 'Kirim ulang kode benefit',

            // CRM + Keuangan (Fase 7)
            'guests:index' => 'Lihat daftar tamu (CRM)',
            'guests:show' => 'Lihat detail tamu (CRM)',
            'ledger:index' => 'Lihat buku kas',
            'ledger:store' => 'Tambah entri buku kas',

            // Reports (Fase 8)
            'reports:view' => 'Lihat laporan & dashboard',

            // Settings (Fase 11)
            'settings:index' => 'Lihat pengaturan',
            'settings:update' => 'Ubah pengaturan',
        ];
    }
}
