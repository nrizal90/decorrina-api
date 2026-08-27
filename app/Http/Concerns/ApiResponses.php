<?php

namespace App\Http\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Konvensi response JSON standar untuk seluruh API De'Corrinna.
 *
 * Bentuk sukses  : { "success": true,  "message": ..., "data": ... }
 * Bentuk paginate: { ..., "data": [...], "meta": { current_page, ... } }
 * Bentuk error   : { "success": false, "message": ..., "errors": ... }
 */
trait ApiResponses
{
    /**
     * Response sukses standar.
     */
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Response sukses untuk resource yang baru dibuat.
     */
    protected function created(mixed $data = null, string $message = 'Data berhasil dibuat'): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    /**
     * Response sukses tanpa body (mis. delete).
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Response error standar.
     *
     * @param  array<string, mixed>|null  $errors
     */
    protected function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Response terpaginasi dengan meta yang konsisten.
     *
     * Menerima LengthAwarePaginator langsung, atau ResourceCollection yang
     * membungkus paginator (mis. UserResource::collection($paginator)).
     */
    protected function paginated(LengthAwarePaginator|ResourceCollection $paginator, string $message = 'OK'): JsonResponse
    {
        if ($paginator instanceof ResourceCollection) {
            /** @var LengthAwarePaginator $source */
            $source = $paginator->resource;
            $items = $paginator->collection;
        } else {
            $source = $paginator;
            $items = collect($paginator->items())->map(
                fn ($item) => $item instanceof JsonResource ? $item : $item
            );
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $items,
            'meta' => [
                'current_page' => $source->currentPage(),
                'per_page' => $source->perPage(),
                'total' => $source->total(),
                'last_page' => $source->lastPage(),
                'from' => $source->firstItem(),
                'to' => $source->lastItem(),
            ],
        ]);
    }
}
