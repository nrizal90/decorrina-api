<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Addon\StoreAddonRequest;
use App\Http\Requests\Addon\UpdateAddonRequest;
use App\Http\Resources\AddonResource;
use App\Models\Addon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Master Add-on (B4). Diproteksi `addons:*` via `rbac`.
 *
 * Tautan ke kategori/item dikirim sebagai dua array id (`category_ids`,
 * `item_ids`) dan dikembalikan sebagai satu array `links` yang datar — bentuk
 * itulah yang dipakai kolom "Terhubung ke Item/Kategori" di AddonMaster.tsx.
 */
class AddonController extends Controller
{
    #[OA\Get(
        path: '/api/admin/addons',
        tags: ['Master Data'],
        summary: 'Daftar add-on',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Cari nama add-on.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['Aktif', 'Nonaktif'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar add-on',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Addon')),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission addons:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $addons = Addon::query()
            ->with(['categories', 'items'])
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'ilike', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(AddonResource::collection($addons));
    }

    #[OA\Post(
        path: '/api/admin/addons',
        tags: ['Master Data'],
        summary: 'Tambah add-on',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AddonInput')),
        responses: [
            new OA\Response(response: 201, description: 'Add-on dibuat', content: new OA\JsonContent(ref: '#/components/schemas/Addon')),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreAddonRequest $request): JsonResponse
    {
        $addon = Addon::create($request->safe()->except(['category_ids', 'item_ids']));
        $this->syncLinks($addon, $request);

        return $this->created(
            // refresh() supaya kolom berdefault DB (`status`) ikut terbaca.
            new AddonResource($addon->refresh()->load(['categories', 'items'])),
            'Add-on berhasil dibuat',
        );
    }

    #[OA\Put(
        path: '/api/admin/addons/{addon}',
        tags: ['Master Data'],
        summary: 'Ubah add-on',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'addon', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AddonInput')),
        responses: [
            new OA\Response(response: 200, description: 'Add-on diperbarui', content: new OA\JsonContent(ref: '#/components/schemas/Addon')),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateAddonRequest $request, Addon $addon): JsonResponse
    {
        $addon->update($request->safe()->except(['category_ids', 'item_ids']));
        $this->syncLinks($addon, $request);

        return $this->ok(
            new AddonResource($addon->refresh()->load(['categories', 'items'])),
            'Add-on berhasil diperbarui',
        );
    }

    #[OA\Delete(
        path: '/api/admin/addons/{addon}',
        tags: ['Master Data'],
        summary: 'Hapus add-on',
        description: 'Soft delete. Tautan ke kategori/item ikut dilepas.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'addon', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Terhapus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Addon $addon): JsonResponse
    {
        $addon->categories()->detach();
        $addon->items()->detach();
        $addon->delete();

        return $this->noContent();
    }

    /**
     * Sinkronkan tautan polimorfik. Hanya relasi yang benar-benar dikirim yang
     * disentuh, supaya PATCH parsial tidak diam-diam melepas tautan lain.
     */
    private function syncLinks(Addon $addon, Request $request): void
    {
        if ($request->has('category_ids')) {
            $addon->categories()->sync($request->input('category_ids', []));
        }

        if ($request->has('item_ids')) {
            $addon->items()->sync($request->input('item_ids', []));
        }
    }
}
