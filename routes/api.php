<?php

use App\Http\Controllers\Api\AddonController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VillaController;
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
| Katalog publik (A2/A3) — tanpa auth & tanpa `rbac`.
| Tenant TIDAK bisa diturunkan dari user di sini (pengunjung anonim, dan
| customer yang login pun tenant_id-nya NULL), jadi dipakai `tenant.public`
| yang membacanya dari header X-Tenant + fallback default.
*/
Route::middleware('tenant.public')->group(function () {
    Route::get('/villas', [VillaController::class, 'index']);
    Route::get('/villas/{slug}', [VillaController::class, 'show']);

    // Kalender pilih tanggal (A4). Publik seperti katalognya: pengunjung
    // anonim memesan lewat layar yang sama, jadi menaruhnya di belakang
    // auth:sanctum akan mematikan alur "lanjutkan tanpa akun".
    Route::get('/villas/{slug}/availability', [VillaController::class, 'availability']);
    Route::get('/villas/{slug}/quote', [VillaController::class, 'quote']);
    Route::get('/villas/{slug}/survey-slots', [VillaController::class, 'surveySlots']);

    /*
    | Booking oleh pengunjung (A6-A10). Publik dengan alasan yang sama seperti
    | katalog: alur pemesanan menyediakan "lanjutkan tanpa akun".
    |
    | `throttle` dipasang karena endpoint ini menulis dan siapa pun bisa
    | memanggilnya — tanpa itu, satu skrip bisa memenuhi kalender.
    */
    Route::post('/public/bookings', [PublicBookingController::class, 'store'])
        ->middleware('throttle:10,1');
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

    /*
    | Booking (B3, Fase 3). `availability` memakai permission bookings:index
    | karena sifatnya membaca — alias didaftarkan di DynamicRBACMiddleware.
    */
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings:index');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings:store');
    Route::get('/bookings/availability', [BookingController::class, 'availability'])->name('bookings:availability');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings:show');
    Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus'])->name('bookings:update-status');

    /*
    | Survey lokasi (B4, Fase 5). Survey dari jalur customer (A5) ikut tampil
    | di sini. TIDAK ada `surveys:show` — permission-nya memang tidak ada di
    | seeder, dan modal detail memakai data baris yang sudah dimuat tabel.
    */
    Route::get('/surveys', [SurveyController::class, 'index'])->name('surveys:index');
    Route::post('/surveys', [SurveyController::class, 'store'])->name('surveys:store');
    Route::match(['put', 'patch'], '/surveys/{survey}', [SurveyController::class, 'update'])->name('surveys:update');

    /*
    | Master Data (B4, Fase 2) — prefix /admin sesuai dok 03.
    | Nama route = nama permission. Aksi yang TIDAK punya permission di seeder
    | sengaja tidak dibuat rutenya (mis. categories:show, facilities:show),
    | karena fail-closed akan selalu menolaknya dengan 403.
    */
    Route::prefix('admin')->group(function () {
        // Kategori (= villa)
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories:index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories:store');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->name('categories:update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories:destroy');

        // Item (kamar/paket)
        Route::get('/items', [ItemController::class, 'index'])->name('items:index');
        Route::post('/items', [ItemController::class, 'store'])->name('items:store');
        Route::get('/items/{item}', [ItemController::class, 'show'])->name('items:show');
        Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update'])->name('items:update');
        Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items:destroy');

        // Fasilitas
        Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities:index');
        Route::post('/facilities', [FacilityController::class, 'store'])->name('facilities:store');
        Route::match(['put', 'patch'], '/facilities/{facility}', [FacilityController::class, 'update'])->name('facilities:update');
        Route::delete('/facilities/{facility}', [FacilityController::class, 'destroy'])->name('facilities:destroy');

        // Add-on
        Route::get('/addons', [AddonController::class, 'index'])->name('addons:index');
        Route::post('/addons', [AddonController::class, 'store'])->name('addons:store');
        Route::match(['put', 'patch'], '/addons/{addon}', [AddonController::class, 'update'])->name('addons:update');
        Route::delete('/addons/{addon}', [AddonController::class, 'destroy'])->name('addons:destroy');
    });
});
