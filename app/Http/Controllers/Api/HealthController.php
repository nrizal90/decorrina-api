<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Health check API — dipakai frontend untuk memastikan koneksi & CORS
     * berfungsi. Kriteria selesai Fase 0: balas 200 tanpa error CORS.
     */
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $database = 'unavailable';
        }

        return $this->ok([
            'service' => config('app.name'),
            'status' => 'up',
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], 'API sehat');
    }
}
