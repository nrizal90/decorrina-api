<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — De'Corrinna
|--------------------------------------------------------------------------
| Semua route diprefix otomatis dengan /api (lihat bootstrap/app.php).
| Endpoint per modul ditambahkan bertahap sesuai roadmap (docs/04).
*/

// Health check — kriteria selesai Fase 0.
Route::get('/health', HealthController::class);

// Contoh route terproteksi Sanctum (dipakai modul Auth di Fase 1).
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
