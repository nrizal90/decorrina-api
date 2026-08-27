<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan ItemPhotoResource.
 */
#[OA\Schema(schema: 'ItemPhoto', title: 'ItemPhoto')]
class ItemPhoto
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'url', type: 'string', example: '/storage/items/kamar-superior-1.jpg')]
    public string $url;

    #[OA\Property(property: 'sort_order', type: 'integer', example: 0)]
    public int $sort_order;

    #[OA\Property(property: 'is_cover', type: 'boolean', example: true)]
    public bool $is_cover;
}
