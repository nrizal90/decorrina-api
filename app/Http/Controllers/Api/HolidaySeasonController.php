<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HolidaySeason\HolidaySeasonRequest;
use App\Http\Resources\HolidaySeasonResource;
use App\Models\HolidaySeason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Master Holiday Season (B4). Diproteksi `holidays:*` via `rbac`.
 *
 * Malam menginap di dalam periode ini ditagih `price_holiday` item — lihat
 * BookingPricing. Daftarnya pendek (belasan per tahun), jadi tanpa paginasi.
 */
class HolidaySeasonController extends Controller
{
    #[OA\Get(
        path: '/api/admin/holiday-seasons',
        tags: ['Master Data'],
        summary: 'Daftar holiday season',
        description: 'Utuh tanpa paginasi, urut tanggal mulai. `year` opsional menyaring periode yang menyentuh tahun itu.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Daftar holiday season'),
            new OA\Response(response: 403, description: 'Tidak punya permission holidays:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $seasons = HolidaySeason::query()
            ->when($request->integer('year'), fn ($query, $year) => $query
                ->whereYear('start_date', '<=', $year)
                ->whereYear('end_date', '>=', $year))
            ->orderBy('start_date')
            ->get();

        return $this->ok(HolidaySeasonResource::collection($seasons));
    }

    #[OA\Post(
        path: '/api/admin/holiday-seasons',
        tags: ['Master Data'],
        summary: 'Tambah holiday season',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Holiday season dibuat'),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(HolidaySeasonRequest $request): JsonResponse
    {
        $season = HolidaySeason::create($request->validated());

        return $this->created(new HolidaySeasonResource($season), 'Holiday season berhasil dibuat');
    }

    #[OA\Put(
        path: '/api/admin/holiday-seasons/{holidaySeason}',
        tags: ['Master Data'],
        summary: 'Ubah holiday season',
        description: 'Booking yang sudah ada TIDAK dihitung ulang — harganya snapshot.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'holidaySeason', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Holiday season diperbarui'),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(HolidaySeasonRequest $request, HolidaySeason $holidaySeason): JsonResponse
    {
        $holidaySeason->update($request->validated());

        return $this->ok(new HolidaySeasonResource($holidaySeason), 'Holiday season berhasil diperbarui');
    }

    #[OA\Delete(
        path: '/api/admin/holiday-seasons/{holidaySeason}',
        tags: ['Master Data'],
        summary: 'Hapus holiday season',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'holidaySeason', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Terhapus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(HolidaySeason $holidaySeason): JsonResponse
    {
        $holidaySeason->delete();

        return $this->noContent();
    }
}
