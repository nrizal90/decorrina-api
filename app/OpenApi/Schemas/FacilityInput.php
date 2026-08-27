<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Body untuk POST/PUT fasilitas.
 */
#[OA\Schema(schema: 'FacilityInput', title: 'FacilityInput', required: ['name'])]
class FacilityInput
{
    #[OA\Property(property: 'name', type: 'string', example: 'Private Pool')]
    public string $name;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, example: 'waves')]
    public ?string $icon;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(
        property: 'category_ids',
        type: 'array',
        items: new OA\Items(type: 'integer'),
        description: 'Villa mana saja yang punya fasilitas ini.',
        example: [1, 2]
    )]
    public array $category_ids;
}
