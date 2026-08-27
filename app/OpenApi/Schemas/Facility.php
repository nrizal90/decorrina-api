<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan FacilityResource.
 */
#[OA\Schema(schema: 'Facility', title: 'Facility')]
class Facility
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'name', type: 'string', example: 'Private Pool')]
    public string $name;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, description: 'Nama ikon lucide.', example: 'waves')]
    public ?string $icon;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;
}
