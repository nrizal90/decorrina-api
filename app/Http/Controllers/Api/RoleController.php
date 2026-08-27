<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\SyncRolePermissionsRequest;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role & matriks permission (B13). Diproteksi `roles:*` via middleware `rbac`.
 *
 * Catatan: role bersifat GLOBAL (dipakai semua tenant) — mengubah permission
 * sebuah role berdampak lintas tenant. Karena itu ada guard anti-eskalasi.
 */
class RoleController extends Controller
{
    private const GUARD = 'web';

    /**
     * Daftar role + permission-nya, plus katalog permission (untuk UI matrix).
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')
            ->where('guard_name', self::GUARD)
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        $catalog = Permission::where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get(['name', 'description']);

        return $this->ok([
            'roles' => $roles,
            'permissions' => $catalog,
        ]);
    }

    /**
     * Timpa permission sebuah role (otoritatif). Route: roles:sync.
     */
    public function sync(SyncRolePermissionsRequest $request, string $role): JsonResponse
    {
        $target = Role::where('name', $role)->where('guard_name', self::GUARD)->firstOrFail();

        // Role vendor tak boleh diubah dari sini.
        if ($target->name === 'superadmin-azatech') {
            return $this->error('Role superadmin-azatech tidak dapat diubah.', 403);
        }

        $permissions = $request->validated('permissions');

        // Cegah eskalasi: hanya azatech yang boleh memberi system-config:manage.
        if (in_array('system-config:manage', $permissions, true)
            && ! $request->user()->hasRole('superadmin-azatech')) {
            return $this->error('Tidak berwenang memberi permission system-config:manage.', 403);
        }

        $target->syncPermissions($permissions);

        return $this->ok([
            'name' => $target->name,
            'permissions' => $target->permissions->pluck('name')->values(),
        ], 'Permission role berhasil diperbarui');
    }
}
