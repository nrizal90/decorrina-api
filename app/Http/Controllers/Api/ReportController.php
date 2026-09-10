<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Support\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * Laporan & Analitik (B10, Fase 8).
 *
 * Satu endpoint untuk ketiga tab: permission-nya memang satu (`reports:view`),
 * dan nama route harus sama dengan nama permission — tiga route dengan nama
 * yang sama tidak bisa dibedakan router. Agregatnya ringan (satu tenant),
 * jadi menghitung ketiganya sekaligus tidak mahal.
 */
class ReportController extends Controller
{
    use ApiResponses;

    /** Jendela default: 30 hari terakhir, sesuai judul grafik di layar. */
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    #[OA\Get(
        path: '/api/reports',
        tags: ['Laporan'],
        summary: 'Occupancy, CRM analytics, dan breakdown pendapatan (B10)',
        description: <<<'TXT'
        Ketiga blok laporan untuk satu rentang tanggal. Tanpa parameter, 30 hari
        terakhir sampai hari ini.

        Okupansi dihitung dari booking yang menghalangi kalender (semua status
        kecuali Dibatalkan). Pendapatan hanya dari DP Dibayar / Lunas / Selesai,
        dan sampai modul pembayaran ada angkanya adalah NILAI booking, bukan
        kas yang diterima.
        TXT,
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', description: 'Eksklusif untuk okupansi (malam tanggal ini tidak dihitung).', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Laporan'),
            new OA\Response(response: 403, description: 'Tidak punya permission reports:view', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Rentang tidak valid', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after:from'],
        ]);

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->startOfDay()
            : Carbon::tomorrow(); // eksklusif: malam ini masih ikut

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_DAYS);

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return $this->error('Rentang laporan maksimal satu tahun.', 422);
        }

        return $this->ok([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'occupancy' => Reports::occupancy($from, $to),
            'crm' => Reports::crm($from, $to),
            'revenue' => Reports::revenue($from, $to),
        ]);
    }
}
