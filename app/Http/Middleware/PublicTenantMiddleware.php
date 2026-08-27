<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis 0 untuk JALUR PUBLIK (katalog villa A2/A3, belum login).
 *
 * `SetTenantMiddleware` menurunkan tenant dari `users.tenant_id`, yang tidak
 * tersedia di sini: pengunjung anonim tidak punya user sama sekali, dan
 * customer yang sudah login justru `tenant_id`-nya NULL (akun global lintas
 * klien — dok 06 §3). Tanpa middleware ini, /api/villas akan mengembalikan
 * katalog SEMUA tenant.
 *
 * Sengaja dibuat middleware terpisah, bukan cabang di dalam SetTenantMiddleware:
 * dengan begitu resolusi berbasis header hanya berlaku pada route publik yang
 * memang memasangnya, dan tidak pernah bisa menimpa konteks tenant user
 * internal di route admin.
 *
 * Sumber tenant: header `X-Tenant` (slug) → fallback `config('tenancy.default_slug')`.
 * Pindah ke skema subdomain nanti = cukup ubah kelas ini.
 */
class PublicTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header(config('tenancy.header'))
            ?: config('tenancy.default_slug');

        $tenant = Tenant::where('slug', $slug)
            ->where('status', 'Aktif')
            ->first();

        if (! $tenant) {
            abort(404, 'Tenant tidak ditemukan atau tidak aktif.');
        }

        app()->instance('currentTenantId', $tenant->id);
        app()->instance('currentTenant', $tenant);

        return $next($request);
    }
}
