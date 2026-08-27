<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan VillaDetailResource (halaman detail publik A3).
 */
#[OA\Schema(schema: 'VillaDetail', title: 'VillaDetail', description: 'Detail villa publik (A3), termasuk add-on yang dipakai layar A7.')]
class VillaDetail
{
    #[OA\Property(property: 'slug', type: 'string', example: 'villa-de-corrinna')]
    public string $slug;

    #[OA\Property(property: 'name', type: 'string', example: 'Villa De Corrinna')]
    public string $name;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, example: 'house')]
    public ?string $icon;

    #[OA\Property(property: 'tagline', type: 'string', nullable: true)]
    public ?string $tagline;

    #[OA\Property(property: 'description', type: 'string', nullable: true, description: 'Isi bagian "Tentang villa ini".')]
    public ?string $description;

    #[OA\Property(property: 'location', type: 'string', nullable: true, example: 'Kawasan Gerbang Gunungsari, Pamijahan, Bogor')]
    public ?string $location;

    #[OA\Property(property: 'coming_soon', type: 'boolean', example: false)]
    public bool $coming_soon;

    #[OA\Property(property: 'price_from', type: 'integer', nullable: true, example: 4000000)]
    public ?int $price_from;

    #[OA\Property(
        property: 'capacity',
        type: 'object',
        properties: [
            new OA\Property(property: 'min', type: 'integer', nullable: true, example: 8),
            new OA\Property(property: 'max', type: 'integer', nullable: true, example: 30),
        ]
    )]
    public object $capacity;

    #[OA\Property(property: 'facilities', type: 'array', items: new OA\Items(ref: '#/components/schemas/Facility'))]
    public array $facilities;

    #[OA\Property(
        property: 'items',
        type: 'array',
        description: 'Hanya item berstatus Aktif, diurutkan dari harga weekday termurah.',
        items: new OA\Items(ref: '#/components/schemas/Item')
    )]
    public array $items;

    #[OA\Property(property: 'addons', type: 'array', items: new OA\Items(ref: '#/components/schemas/Addon'))]
    public array $addons;

    #[OA\Property(property: 'photos', type: 'array', items: new OA\Items(ref: '#/components/schemas/ItemPhoto'), description: 'Menyusul bersama endpoint upload foto.')]
    public array $photos;
}
