<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit T-06: 5 percobaan login/menit per (email, IP).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Lapis 1 — Superadmin bypass. Role vendor `superadmin-azatech` lolos
        // SEMUA pengecekan permission. `superadmin-klien` TIDAK dapat bypass
        // (ia diberi hampir semua permission via seeder, kecuali
        // system-config:manage). Lihat docs/06-rbac-decorina.md §4.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('superadmin-azatech') ? true : null; // null = lanjut cek normal
        });
    }
}
