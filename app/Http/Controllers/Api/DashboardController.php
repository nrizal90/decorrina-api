<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Support\Dashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Dashboard admin (B1, Fase 8). Rutenya bernama `dashboard:view` dan
 * dialiaskan ke `reports:view` di DynamicRBACMiddleware — permission-nya
 * memang satu ("Lihat laporan & dashboard"), tapi dua route tidak boleh
 * berbagi nama.
 */
class DashboardController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/dashboard',
        tags: ['Laporan'],
        summary: 'KPI, tren booking, dan aktivitas terbaru (B1)',
        description: 'Kartu `revenue` null bila user tidak punya ledger:index (Staff).',
        responses: [
            new OA\Response(response: 200, description: 'Dashboard'),
            new OA\Response(response: 403, description: 'Tidak punya permission reports:view', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->ok(Dashboard::build($request->user()));
    }
}
