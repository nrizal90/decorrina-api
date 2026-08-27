<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis 2 — RBAC dinamis. Gating modul berdasar NAMA ROUTE.
 *
 * Pola: route name = permission name = FE guard string (kontrak dok 06 §1).
 * Fail-closed: route bisnis di belakang middleware ini WAJIB `->name()`.
 * Route tanpa nama → 403 (bukan lolos diam-diam).
 *
 * Berbeda dari BKI, Decorina TANPA approval berjenjang → pemetaan
 * route→permission bersifat 1:1. Alias eksplisit tetap didukung bila dua route
 * berbagi satu gate (mis. route:cache menolak nama route duplikat).
 *
 * Lihat docs/06-rbac-decorina.md §5. Alias 'rbac' di bootstrap/app.php.
 */
class DynamicRBACMiddleware
{
    /**
     * Alias eksplisit: route name => permission yang dipakai.
     * Kosongkan bila 1:1. Contoh: 'bookings:cancel' => 'bookings:update-status'.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        // Fail-closed: tanpa nama route, tolak.
        if (! $routeName) {
            Log::warning('RBAC: route tanpa nama ditolak (fail-closed).', [
                'uri' => $request->getRequestUri(),
                'ip' => $request->ip(),
            ]);
            abort(403, 'Akses ditolak: route tidak terdaftar di RBAC.');
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Belum terautentikasi.');
        }

        $permissions = $this->resolvePermissions($routeName);

        if (collect($permissions)->some(fn (string $p) => $user->can($p))) {
            return $next($request);
        }

        Log::warning('RBAC: akses ditolak.', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'roles' => $user->getRoleNames(),
            'required_permission' => $permissions,
            'route' => $routeName,
            'ip' => $request->ip(),
        ]);

        abort(403, 'Tidak punya akses untuk tindakan ini.');
    }

    /**
     * Daftar permission yang mengizinkan route ini (OR — punya salah satu = lolos).
     *
     * @return array<int, string>
     */
    protected function resolvePermissions(string $routeName): array
    {
        return [$this->aliases[$routeName] ?? $routeName];
    }
}
