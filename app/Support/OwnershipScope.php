<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Lapis 3 — Ownership (halus). Dipanggil dari Service layer, BUKAN controller.
 *
 * Aturan Decorina: HANYA Customer yang dibatasi ke datanya sendiri
 * (`user_id`). Staff/Admin lihat semua dalam tenant (sudah dibatasi Global
 * Scope Lapis 0). Superadmin Azatech lihat lintas tenant.
 *
 * Karena akun Customer lintas tenant (tenant_id NULL), pembatasan pakai
 * ownership `user_id` — bukan tenant scope. Lihat docs/06-rbac-decorina.md §6.
 *
 * Contoh pemakaian (Fase 3):
 *   OwnershipScope::forCustomer(Booking::query(), $user)->paginate();
 */
class OwnershipScope
{
    public static function forCustomer(Builder $query, User $user, string $column = 'user_id'): Builder
    {
        if ($user->hasRole('customer')) {
            $query->where($column, $user->id);
        }

        return $query;
    }
}
