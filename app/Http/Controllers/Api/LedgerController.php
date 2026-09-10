<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ledger\StoreLedgerEntryRequest;
use App\Http\Resources\LedgerEntryResource;
use App\Models\LedgerEntry;
use App\Support\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * Akuntansi & Keuangan (B9, Fase 7).
 *
 * Satu endpoint baca untuk ketiga tab (ledger harian, laporan bulanan, bagi
 * hasil): permission bacanya satu (`ledger:index`) dan nama route = nama
 * permission. Yang dikembalikan disesuaikan parameter — tab yang tidak
 * diminta tidak dihitung.
 *
 * Staff sengaja TIDAK punya `ledger:*` (seeder): keuangan hanya untuk Admin
 * ke atas dan Stakeholder (baca).
 */
class LedgerController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/ledger',
        tags: ['Keuangan'],
        summary: 'Buku kas, laporan bulanan, dan bagi hasil (B9)',
        description: <<<'TXT'
        - Tanpa parameter khusus: entri buku kas untuk rentang `from`/`to`
          (default bulan berjalan) beserta total pemasukan/pengeluaran.
        - `month=YYYY-MM`: ringkasan bulan itu + rincian per kategori.
        - `profit_share=<slug villa>`: bagi hasil per bulan untuk villa yang
          punya skema di config/finance.php (sementara dipatok, menunggu modul
          joint venture).

        `months` (bulan yang punya entri) dan `categories` (saran kategori)
        selalu ikut, untuk dropdown di layar.
        TXT,
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', description: 'Pemasukan | Pengeluaran', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'month', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-09')),
            new OA\Parameter(name: 'profit_share', in: 'query', schema: new OA\Schema(type: 'string', example: 'villa-cendana-wangi')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Buku kas'),
            new OA\Response(response: 403, description: 'Tidak punya permission ledger:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:'.implode(',', LedgerEntry::TYPES)],
            'month' => ['nullable', 'date_format:Y-m'],
            'profit_share' => ['nullable', 'string'],
        ]);

        $from = $request->filled('from') ? $request->string('from')->toString() : Carbon::today()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? $request->string('to')->toString() : Carbon::today()->endOfMonth()->toDateString();

        $entries = LedgerEntry::query()
            ->with('booking:id,kode_booking')
            ->between($from, $to)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        $income = (int) $entries->where('type', LedgerEntry::TYPE_PEMASUKAN)->sum('amount');
        $expense = (int) $entries->where('type', LedgerEntry::TYPE_PENGELUARAN)->sum('amount');

        $payload = [
            'from' => $from,
            'to' => $to,
            'entries' => LedgerEntryResource::collection($entries),
            'totals' => ['income' => $income, 'expense' => $expense, 'net' => $income - $expense],
            'months' => Ledger::monthsWithEntries(),
            'categories' => config('finance.categories'),
        ];

        if ($request->filled('month')) {
            $payload['monthly'] = Ledger::monthly(Carbon::createFromFormat('Y-m', $request->string('month')->toString()));
        }

        if ($request->filled('profit_share')) {
            $payload['profit_share'] = Ledger::profitShare($request->string('profit_share')->toString());
        }

        return $this->ok($payload);
    }

    #[OA\Post(
        path: '/api/ledger',
        tags: ['Keuangan'],
        summary: 'Catat transaksi manual (B9)',
        responses: [
            new OA\Response(response: 201, description: 'Entri dicatat'),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreLedgerEntryRequest $request): JsonResponse
    {
        $entry = LedgerEntry::create($request->safe()->all() + [
            'created_by' => $request->user()?->id,
        ]);

        return $this->created(new LedgerEntryResource($entry->load('booking:id,kode_booking')), 'Transaksi dicatat');
    }
}
