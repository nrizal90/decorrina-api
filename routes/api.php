<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — De'Corrinna
|--------------------------------------------------------------------------
| Semua route diprefix otomatis dengan /api (lihat bootstrap/app.php).
| Endpoint per modul ditambahkan bertahap sesuai roadmap (docs/04).
|
| Konvensi RBAC (docs/06-rbac-decorina.md):
|  - Auth (register/login/logout/me): TANPA ->name(), TANPA middleware `rbac`.
|  - Route bisnis: ['auth:sanctum','tenant','rbac'] + ->name('{resource}:{action}').
*/

// Health check — kriteria selesai Fase 0.
Route::get('/health', HealthController::class);

/*
| Auth (A11) — publik & non-RBAC.
*/
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

/*
| Route bisnis terproteksi RBAC.
| Wajib ->name('{resource}:{action}') (fail-closed: tanpa nama → 403).
*/
Route::middleware(['auth:sanctum', 'tenant', 'rbac'])->group(function () {
    // Manajemen user (B13)
    Route::get('/users', [UserController::class, 'index'])->name('users:index');
    Route::post('/users', [UserController::class, 'store'])->name('users:store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users:show');
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->name('users:update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users:destroy');

    // Role & matriks permission (B13)
    Route::get('/roles', [RoleController::class, 'index'])->name('roles:index');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'sync'])->name('roles:sync');
});
