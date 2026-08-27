<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        // Lapis 1 — Superadmin bypass. Role vendor `superadmin-azatech` lolos
        // SEMUA pengecekan permission. `superadmin-klien` TIDAK dapat bypass
        // (ia diberi hampir semua permission via seeder, kecuali
        // system-config:manage). Lihat docs/06-rbac-decorina.md §4.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('superadmin-azatech') ? true : null; // null = lanjut cek normal
        });
    }
}
