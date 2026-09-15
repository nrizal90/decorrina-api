<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ItemPhoto\ReorderItemPhotosRequest;
use App\Http\Requests\ItemPhoto\StoreItemPhotoRequest;
use App\Http\Resources\ItemPhotoResource;
use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

/**
 * Foto item (B4). Semua route beralias ke permission items:update — foto
 * adalah bagian dari item, bukan entitas dengan hak akses sendiri.
 *
 * Disk dari `booking.photos_disk` (env PHOTOS_DISK): lokal sekarang, s3
 * nanti tanpa mengubah kode di sini. `{item}` sudah kena tenant scope, dan
 * `{photo}` diambil lewat relasi item (scoped binding) — foto item lain 404.
 */
class ItemPhotoController extends Controller
{
    #[OA\Post(
        path: '/admin/items/{item}/photos',
        summary: 'Upload satu foto item',
        security: [['sanctum' => []]],
        tags: ['Master Data — Item'],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['photo'], properties: [
                new OA\Property(property: 'photo', type: 'string', format: 'binary', description: 'jpg/png/webp, maks 5 MB'),
            ]),
        )),
        responses: [
            new OA\Response(response: 201, description: 'Foto tersimpan'),
            new OA\Response(response: 422, description: 'File tidak valid atau kuota foto item penuh', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreItemPhotoRequest $request, Item $item): JsonResponse
    {
        $max = (int) config('booking.max_photos_per_item');
        if ($item->photos()->count() >= $max) {
            return $this->error("Maksimal {$max} foto per item.", 422);
        }

        // store() memberi nama acak — nama asli dari user tidak pernah dipakai.
        $path = $request->file('photo')->store("items/{$item->id}", self::disk());

        $photo = $item->photos()->create([
            'path' => $path,
            'sort_order' => $item->photos()->max('sort_order') + 1,
            'is_cover' => ! $item->photos()->where('is_cover', true)->exists(),
        ]);

        return $this->created(new ItemPhotoResource($photo), 'Foto berhasil diunggah');
    }

    #[OA\Put(
        path: '/admin/items/{item}/photos/order',
        summary: 'Atur urutan & cover foto item',
        security: [['sanctum' => []]],
        tags: ['Master Data — Item'],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['photos'], properties: [
            new OA\Property(property: 'photos', type: 'array', items: new OA\Items(type: 'integer'), description: 'Seluruh id foto item, urutan baru'),
            new OA\Property(property: 'cover_id', type: 'integer', nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'Daftar foto setelah diurutkan')]
    )]
    public function reorder(ReorderItemPhotosRequest $request, Item $item): JsonResponse
    {
        $ids = $request->validated('photos');
        $coverId = $request->validated('cover_id');

        $owned = $item->photos()->pluck('id')->all();
        if (array_diff($ids, $owned) !== [] || ($coverId !== null && ! in_array($coverId, $owned))) {
            return $this->error('Ada id foto yang bukan milik item ini.', 422);
        }

        DB::transaction(function () use ($item, $ids, $coverId) {
            foreach ($ids as $order => $id) {
                $item->photos()->whereKey($id)->update([
                    'sort_order' => $order,
                    'is_cover' => $coverId === null ? DB::raw('is_cover') : (int) ($id === $coverId),
                ]);
            }
        });

        return $this->ok(ItemPhotoResource::collection($item->photos()->get()), 'Urutan foto diperbarui');
    }

    #[OA\Delete(
        path: '/admin/items/{item}/photos/{photo}',
        summary: 'Hapus foto item',
        security: [['sanctum' => []]],
        tags: ['Master Data — Item'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'photo', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Foto dihapus')]
    )]
    public function destroy(Item $item, ItemPhoto $photo): JsonResponse
    {
        DB::transaction(function () use ($item, $photo) {
            $wasCover = $photo->is_cover;
            $photo->delete();
            Storage::disk(self::disk())->delete($photo->path);

            // Cover jangan sampai kosong selama masih ada foto.
            if ($wasCover && ($next = $item->photos()->first())) {
                $next->update(['is_cover' => true]);
            }
        });

        return $this->ok(null, 'Foto berhasil dihapus');
    }

    private static function disk(): string
    {
        return config('booking.photos_disk');
    }
}
