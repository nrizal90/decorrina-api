<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan AddonResource.
 */
#[OA\Schema(schema: 'Addon', title: 'Addon')]
class Addon
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'name', type: 'string', example: 'Extra Bed')]
    public string $name;

    #[OA\Property(property: 'price', type: 'integer', example: 150000)]
    public int $price;

    #[OA\Property(property: 'description', type: 'string', nullable: true)]
    public ?string $description;

    #[OA\Property(
        property: 'unit',
        type: 'string',
        nullable: true,
        description: 'Satuan tampilan di A7: "unit" (Extra Bed), "jam" (Late Checkout), atau null untuk harga flat.',
        example: 'unit'
    )]
    public ?string $unit;

    #[OA\Property(property: 'deadline_days', type: 'integer', nullable: true, description: 'Batas pemesanan H-n sebelum check-in (mis. catering H-4).', example: 4)]
    public ?int $deadline_days;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(
        property: 'links',
        type: 'array',
        description: 'Bentuk datar dari pivot polimorfik — mengisi kolom "Terhubung ke Item/Kategori".',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['category', 'item']),
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'label', type: 'string'),
            ],
            type: 'object'
        ),
        example: [['type' => 'category', 'id' => 1, 'label' => 'Villa De Corrinna']]
    )]
    public array $links;

    #[OA\Property(property: 'created_at', type: 'string', format: 'date-time')]
    public string $created_at;
}
