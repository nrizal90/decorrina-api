<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Body untuk POST/PUT kategori. Cerminan StoreCategoryRequest/UpdateCategoryRequest.
 */
#[OA\Schema(schema: 'CategoryInput', title: 'CategoryInput', required: ['name'])]
class CategoryInput
{
    #[OA\Property(property: 'name', type: 'string', example: 'Villa De Corrinna')]
    public string $name;

    #[OA\Property(property: 'slug', type: 'string', description: 'Opsional — diturunkan dari `name` bila kosong, lalu dibuat unik per tenant.', example: 'villa-de-corrinna')]
    public string $slug;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, example: 'house')]
    public ?string $icon;

    #[OA\Property(property: 'description', type: 'string', nullable: true)]
    public ?string $description;

    #[OA\Property(property: 'tagline', type: 'string', nullable: true)]
    public ?string $tagline;

    #[OA\Property(property: 'location', type: 'string', nullable: true)]
    public ?string $location;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(
        property: 'facility_ids',
        type: 'array',
        items: new OA\Items(type: 'integer'),
        description: 'Sync fasilitas villa. Bila field ini tidak dikirim sama sekali, tautan lama dibiarkan apa adanya.',
        example: [1, 2]
    )]
    public array $facility_ids;
}
