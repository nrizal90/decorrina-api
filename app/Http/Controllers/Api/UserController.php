<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Manajemen user (B13). Semua route diproteksi `users:*` via middleware `rbac`.
 * List/CRUD otomatis tersekat tenant lewat Global Scope BelongsToTenant di User
 * (aktif untuk aktor internal; azatech lintas tenant).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('q'), fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'ilike', '%'.$request->string('q').'%')
                    ->orWhere('email', 'ilike', '%'.$request->string('q').'%')
            ))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(UserResource::collection($users));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok(new UserResource($user));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $roles = $this->guardRoles($request->validated('roles'), $actor);

        // Aktor azatech boleh set tenant tujuan; selain itu ikut tenant aktif
        // (auto-fill BelongsToTenant). NULL tetap NULL bila tak ada konteks.
        $tenantId = $actor->hasRole('superadmin-azatech')
            ? $request->validated('tenant_id')
            : (app()->bound('currentTenantId') ? app('currentTenantId') : null);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
            // Default 'Aktif' agar form yang belum mengirim status tetap sah.
            'status' => $request->validated('status') ?? 'Aktif',
        ]);

        $user->syncRoles($roles);

        return $this->created(new UserResource($user->refresh()), 'User berhasil dibuat');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->safe()->only(['name', 'email', 'status']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->validated('password'));
        }
        $user->update($data);

        // Menonaktifkan akun harus langsung memutus akses, bukan menunggu
        // token kedaluwarsa — tanpa ini user nonaktif tetap bisa memakai
        // token yang sudah terbit sebelumnya.
        if (! $user->isActive()) {
            $user->tokens()->delete();
        }

        if ($request->has('roles')) {
            $roles = $this->guardRoles($request->validated('roles'), $request->user());
            $user->syncRoles($roles);
        }

        return $this->ok(new UserResource($user->refresh()), 'User berhasil diperbarui');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            return $this->error('Tidak bisa menghapus akun sendiri.', 422);
        }

        $user->delete();

        return $this->noContent();
    }

    /**
     * Cegah eskalasi: hanya superadmin-azatech yang boleh menetapkan role
     * `superadmin-azatech` ke user lain.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function guardRoles(array $roles, User $actor): array
    {
        if (in_array('superadmin-azatech', $roles, true) && ! $actor->hasRole('superadmin-azatech')) {
            abort(403, 'Tidak berwenang menetapkan role superadmin-azatech.');
        }

        return $roles;
    }
}
