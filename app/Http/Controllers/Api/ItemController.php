<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Master Item (B4 — ItemList & ItemForm). Diproteksi `items:*` via `rbac`.
 * Tersekat tenant lewat Global Scope BelongsToTenant.
 */
class ItemController extends Controller
{
    #[OA\Get(
        path: '/api/admin/items',
        tags: ['Master Data'],
        summary: 'Daftar item',
        description: 'Terpaginasi. Parameter `q`, `status`, dan `category_id` memetakan langsung ke kotak pencarian dan filter di ItemList.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Cari nama item.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['Aktif', 'Nonaktif'])),
            new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar item',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Item')),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission items:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $items = Item::query()
            ->with(['category', 'photos'])
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'ilike', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(ItemResource::collection($items));
    }

    #[OA\Get(
        path: '/api/admin/items/{item}',
        tags: ['Master Data'],
        summary: 'Detail item',
        description: 'Dipakai ItemForm saat mode edit untuk mengisi ketiga tab.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Detail item', content: new OA\JsonContent(ref: '#/components/schemas/Item')),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Item $item): JsonResponse
    {
        return $this->ok(new ItemResource($item->load(['category', 'photos'])));
    }

    #[OA\Post(
        path: '/api/admin/items',
        tags: ['Master Data'],
        summary: 'Tambah item',
        description: 'Tanpa `status`, item tersimpan sebagai `Nonaktif` — inilah perilaku tombol "Simpan Draft"; "Simpan & Aktifkan" mengirim `status: "Aktif"`.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ItemInput')),
        responses: [
            new OA\Response(response: 201, description: 'Item dibuat', content: new OA\JsonContent(ref: '#/components/schemas/Item')),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = Item::create($request->validated());

        return $this->created(
            // refresh() supaya kolom berdefault DB (mis. `status` = Nonaktif saat
            // "Simpan Draft") ikut terbaca, bukan null.
            new ItemResource($item->refresh()->load(['category', 'photos'])),
            'Item berhasil dibuat',
        );
    }

    #[OA\Put(
        path: '/api/admin/items/{item}',
        tags: ['Master Data'],
        summary: 'Ubah item',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ItemInput')),
        responses: [
            new OA\Response(response: 200, description: 'Item diperbarui', content: new OA\JsonContent(ref: '#/components/schemas/Item')),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $item->update($request->validated());

        return $this->ok(
            new ItemResource($item->refresh()->load(['category', 'photos'])),
            'Item berhasil diperbarui',
        );
    }

    #[OA\Delete(
        path: '/api/admin/items/{item}',
        tags: ['Master Data'],
        summary: 'Hapus item',
        description: 'Soft delete — booking lama yang menunjuk item ini tetap utuh.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Terhapus'),
            new OA\Response(response: 404, description: 'Tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return $this->noContent();
    }
}
