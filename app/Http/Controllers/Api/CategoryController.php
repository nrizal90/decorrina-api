<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * Master Kategori (B4). Kategori = "villa" di katalog publik.
 * Diproteksi `categories:*` via middleware `rbac`; tersekat tenant lewat
 * Global Scope BelongsToTenant.
 *
 * Catatan: seeder RBAC tidak mendefinisikan permission `categories:show`, jadi
 * endpoint detail sengaja tidak dibuat (fail-closed — route tanpa permission
 * yang cocok akan selalu 403).
 */
class CategoryController extends Controller
{
    #[OA\Get(
        path: '/api/admin/categories',
        tags: ['Master Data'],
        summary: 'Daftar kategori (villa)',
        description: 'Terpaginasi. Menyertakan `items_count` untuk kolom "Jumlah Item" di layar B4.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Cari berdasarkan nama.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['Aktif', 'Nonaktif'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar kategori',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Category')),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission categories:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->withCount('items')
            ->with('facilities')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'ilike', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(CategoryResource::collection($categories));
    }

    #[OA\Post(
        path: '/api/admin/categories',
        tags: ['Master Data'],
        summary: 'Tambah kategori',
        description: '`slug` boleh dikosongkan — otomatis diturunkan dari `name` dan dibuat unik per tenant.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CategoryInput')),
        responses: [
            new OA\Response(response: 201, description: 'Kategori dibuat', content: new OA\JsonContent(ref: '#/components/schemas/Category')),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->safe()->except('facility_ids');
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);

        $category = Category::create($data);

        if ($request->has('facility_ids')) {
            $category->facilities()->sync($request->validated('facility_ids'));
        }

        return $this->created(
            // refresh() supaya kolom berdefault DB (`status`) ikut terbaca.
            new CategoryResource($category->refresh()->loadCount('items')->load('facilities')),
            'Kategori berhasil dibuat',
        );
    }

    #[OA\Put(
        path: '/api/admin/categories/{category}',
        tags: ['Master Data'],
        summary: 'Ubah kategori',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CategoryInput')),
        responses: [
            new OA\Response(response: 200, description: 'Kategori diperbarui', content: new OA\JsonContent(ref: '#/components/schemas/Category')),
            new OA\Response(response: 404, description: 'Tidak ditemukan (atau milik tenant lain)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->safe()->except('facility_ids');
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
        }

        $category->update($data);

        if ($request->has('facility_ids')) {
            $category->facilities()->sync($request->validated('facility_ids'));
        }

        return $this->ok(
            new CategoryResource($category->refresh()->loadCount('items')->load('facilities')),
            'Kategori berhasil diperbarui',
        );
    }

    #[OA\Delete(
        path: '/api/admin/categories/{category}',
        tags: ['Master Data'],
        summary: 'Hapus kategori',
        description: 'Soft delete. Ditolak bila masih punya item — hapus/pindahkan itemnya lebih dulu.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Terhapus'),
            new OA\Response(response: 422, description: 'Masih punya item', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Category $category): JsonResponse
    {
        if ($category->items()->exists()) {
            return $this->error('Kategori masih punya item. Hapus atau pindahkan itemnya lebih dulu.', 422);
        }

        $category->delete();

        return $this->noContent();
    }

    /**
     * Slug unik per tenant. Global Scope BelongsToTenant sudah membatasi
     * pencarian ke tenant aktif, jadi cek ini tidak bocor lintas klien.
     */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $suffix = 2;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
