<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan VillaResource (kartu katalog publik A2).
 */
#[OA\Schema(schema: 'Villa', title: 'Villa', description: 'Kartu villa di katalog publik (A2).')]
class Villa
{
    #[OA\Property(property: 'slug', type: 'string', example: 'villa-de-corrinna')]
    public string $slug;

    #[OA\Property(property: 'name', type: 'string', example: 'Villa De Corrinna')]
    public string $name;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, example: 'house')]
    public ?string $icon;

    #[OA\Property(property: 'tagline', type: 'string', nullable: true, example: 'Rumah utama dengan taman luas dan kolam privat.')]
    public ?string $tagline;

    #[OA\Property(
        property: 'coming_soon',
        type: 'boolean',
        description: 'true bila kategori nonaktif ATAU belum punya item aktif. Kartu tetap dikembalikan, frontend menampilkannya diredupkan dengan label "Segera hadir".',
        example: false
    )]
    public bool $coming_soon;

    #[OA\Property(
        property: 'price_from',
        type: 'integer',
        nullable: true,
        description: 'Harga weekday termurah dari item aktif. null bila coming_soon.',
        example: 4000000
    )]
    public ?int $price_from;

    #[OA\Property(
        property: 'capacity',
        type: 'object',
        description: 'Rentang gabungan dari item aktif — mengisi teks "8–30 orang".',
        properties: [
            new OA\Property(property: 'min', type: 'integer', nullable: true, example: 8),
            new OA\Property(property: 'max', type: 'integer', nullable: true, example: 30),
        ]
    )]
    public object $capacity;

    #[OA\Property(property: 'photo', type: 'string', nullable: true, description: 'Menyusul bersama endpoint upload foto.', example: null)]
    public ?string $photo;
}
