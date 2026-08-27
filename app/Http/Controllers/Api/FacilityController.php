<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facility\StoreFacilityRequest;
use App\Http\Requests\Facility\UpdateFacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Master Fasilitas (B4). Diproteksi `facilities:*` via `rbac`.
 *
 * FacilityMaster.tsx menampilkan semua fasilitas sekaligus sebagai chip (tanpa
 * paginasi), jadi index sengaja mengembalikan daftar utuh, bukan paginator.
 */
class FacilityController extends Controller
{
    #[OA\Get(
        path: '/api/admin/facilities',
        tags: ['Master Data'],
        summary: 'Daftar fasilitas',
        description: 'Dikembalikan utuh tanpa paginasi — layar B4 menampilkannya sebagai deretan chip.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar fasilitas',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Facility')),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission facilities:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $facilities = Facility::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->get();

        return $this->ok(FacilityResource::collection($facilities));
    }

    #[OA\Post(
        path: '/api/admin/facilities',
        tags: ['Master Data'],
        summary: 'Tambah fasilitas',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityInput')),
        responses: [
            new OA\Response(response: 201, description: 'Fasilitas dibuat', content: new OA\JsonContent(ref: '#/components/schemas/Facility')),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreFacilityRequest $request): JsonResponse
    {
        $facility = Facility::create($request->safe()->except('category_ids'));

        if ($request->has('category_ids')) {
            $facility->categories()->sync($request->validated('category_ids'));
        }

        // refresh() supaya kolom berdefault DB (`status`) ikut terbaca.
        return $this->created(new FacilityResource($facility->refresh()), 'Fasilitas berhasil dibuat');
    }

    #[OA\Put(
        path: '/api/admin/facilities/{facility}',
        tags: ['Master Data'],
        summary: 'Ubah fasilitas',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'facility', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityInput')),
        responses: [
            new OA\Response(response: 200, description: 'Fasilitas diperbarui', content: new OA\JsonContent(ref: '#/components/schemas/Facility')),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateFacilityRequest $request, Facility $facility): JsonResponse
    {
        $facility->update($request->safe()->except('category_ids'));

        if ($request->has('category_ids')) {
            $facility->categories()->sync($request->validated('category_ids'));
        }

        return $this->ok(new FacilityResource($facility->refresh()), 'Fasilitas berhasil diperbarui');
    }

    #[OA\Delete(
        path: '/api/admin/facilities/{facility}',
        tags: ['Master Data'],
        summary: 'Hapus fasilitas',
        description: 'Soft delete. Tautan ke kategori ikut dilepas.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'facility', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Terhapus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Facility $facility): JsonResponse
    {
        $facility->categories()->detach();
        $facility->delete();

        return $this->noContent();
    }
}
