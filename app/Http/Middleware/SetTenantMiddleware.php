<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis 0 — Tenant Isolation. Menentukan konteks tenant tiap request untuk
 * mengaktifkan Global Scope BelongsToTenant.
 *
 * Cabang berdasar kolom `users.tenant_id`:
 *  - tenant_id TERISI  → user internal klien. Ikat `currentTenantId` → Global
 *    Scope memfilter data ke tenant tersebut. Header X-Tenant DIABAIKAN di
 *    cabang ini, supaya user klien tak bisa mengintip tenant lain.
 *  - tenant_id NULL    → superadmin-azatech atau customer.
 *      · azatech  → boleh menyebut tenant tujuan lewat header `X-Tenant`
 *        (slug). Tanpa header ia tetap lintas tenant (Gate::before bypass),
 *        tapi request TULIS ditolak karena tenant tujuannya ambigu.
 *      · customer → tanpa tenant scope; isolasi lewat ownership di Service.
 *
 * Kenapa azatech perlu override: seluruh tabel domain punya `tenant_id NOT
 * NULL`, jadi tanpa konteks tenant ia tak bisa membuat master data atas nama
 * klien sama sekali. Lihat docs/06-rbac-decorina.md §3 dan utang teknis di
 * docs/SESSION-2026-08-27-fase1.md.
 *
 * Alias 'tenant' di bootstrap/app.php.
 */
class SetTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id !== null) {
            // User internal klien — aktifkan Global Scope untuk tenant miliknya.
            app()->instance('currentTenantId', $user->tenant_id);

            return $next($request);
        }

        if ($user && $user->hasRole('superadmin-azatech')) {
            $this->resolveForAzatech($request);
        }

        // Customer (tenant_id NULL, bukan azatech) → tanpa scope.

        return $next($request);
    }

    /**
     * Terapkan tenant yang disebut vendor lewat header, bila ada.
     */
    private function resolveForAzatech(Request $request): void
    {
        $slug = $request->header(config('tenancy.header'));

        if (! $slug) {
            // Membaca lintas tenant tetap boleh (itu gunanya akun vendor), tapi
            // menulis tanpa menyebut tenant tujuan akan menghasilkan baris tanpa
            // pemilik — ditolak dengan pesan jelas, bukan error 500 dari DB.
            if (! $request->isMethodSafe()) {
                abort(422, 'Sertakan header X-Tenant untuk menentukan klien yang dituju.');
            }

            return;
        }

        $tenant = Tenant::where('slug', $slug)->where('status', 'Aktif')->first();

        if (! $tenant) {
            abort(404, 'Tenant tidak ditemukan atau tidak aktif.');
        }

        app()->instance('currentTenantId', $tenant->id);
        app()->instance('currentTenant', $tenant);
    }
}
