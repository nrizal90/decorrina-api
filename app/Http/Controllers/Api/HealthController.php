<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    /**
     * Health check API — dipakai frontend untuk memastikan koneksi & CORS
     * berfungsi. Kriteria selesai Fase 0: balas 200 tanpa error CORS.
     */
    #[OA\Get(
        path: '/api/health',
        tags: ['System'],
        summary: 'Health check API',
        description: 'Mengecek API hidup dan koneksi database. Tidak butuh autentikasi.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'API sehat',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'service', type: 'string', example: "De'Corrinna API"),
                                new OA\Property(property: 'status', type: 'string', example: 'up'),
                                new OA\Property(property: 'database', type: 'string', example: 'ok'),
                                new OA\Property(property: 'time', type: 'string', format: 'date-time', example: '2026-08-27T01:45:18+00:00'),
                            ]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
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
