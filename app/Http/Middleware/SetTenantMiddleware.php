<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis 0 — Tenant Isolation. Menentukan konteks tenant tiap request untuk
 * mengaktifkan Global Scope BelongsToTenant.
 *
 * Cabang murni berdasar kolom `users.tenant_id`:
 *  - tenant_id TERISI  → user internal. Ikat `currentTenantId` → Global Scope
 *    memfilter data ke tenant tersebut.
 *  - tenant_id NULL    → superadmin-azatech atau customer. Tanpa tenant scope;
 *    isolasi lewat Gate::before (azatech) atau ownership di Service (customer).
 *
 * Catatan: RBAC (role/permission) TIDAK bergantung tenant — role global
 * (standar Spatie, tanpa fitur teams). Lihat docs/06-rbac-decorina.md §3.
 * Alias 'tenant' di bootstrap/app.php.
 */
class SetTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id !== null) {
            // User internal — aktifkan Global Scope untuk tenant ini.
            app()->instance('currentTenantId', $user->tenant_id);
        }

        // tenant_id NULL (azatech/customer) → tanpa scope (lihat catatan kelas).

        return $next($request);
    }
}
