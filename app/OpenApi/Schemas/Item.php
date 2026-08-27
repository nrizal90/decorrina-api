<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan ItemResource.
 */
#[OA\Schema(schema: 'Item', title: 'Item', description: 'Kamar/paket di dalam sebuah kategori (villa).')]
class Item
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'category_id', type: 'integer', example: 1)]
    public int $category_id;

    #[OA\Property(property: 'name', type: 'string', example: 'Kamar Superior')]
    public string $name;

    #[OA\Property(property: 'description', type: 'string', nullable: true)]
    public ?string $description;

    #[OA\Property(property: 'capacity_type', type: 'string', enum: ['Tetap', 'Rentang'], example: 'Rentang')]
    public string $capacity_type;

    #[OA\Property(property: 'cap_min', type: 'integer', example: 8)]
    public int $cap_min;

    #[OA\Property(property: 'cap_max', type: 'integer', example: 12)]
    public int $cap_max;

    #[OA\Property(property: 'price_weekday', type: 'integer', description: 'Rupiah sebagai integer, tidak pernah float.', example: 4000000)]
    public int $price_weekday;

    #[OA\Property(property: 'price_weekend', type: 'integer', example: 4800000)]
    public int $price_weekend;

    #[OA\Property(
        property: 'payment_mode',
        type: 'string',
        enum: ['Full Payment', 'DP + Pelunasan', 'Keduanya — customer memilih'],
        description: 'Nilai persis seperti label radio di ItemForm. ⚠ Kebijakan default per item belum final (dok 05) — backend hanya menyimpan pilihan admin.',
        example: 'Full Payment'
    )]
    public string $payment_mode;

    #[OA\Property(property: 'dp_minimum', type: 'integer', nullable: true, description: 'Wajib bila payment_mode memakai DP.', example: 2000000)]
    public ?int $dp_minimum;

    #[OA\Property(property: 'requires_survey', type: 'boolean', example: false)]
    public bool $requires_survey;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(
        property: 'photos',
        type: 'array',
        items: new OA\Items(ref: '#/components/schemas/ItemPhoto'),
        description: 'Selalu ada; masih kosong sampai endpoint upload dibuat.'
    )]
    public array $photos;

    #[OA\Property(property: 'created_at', type: 'string', format: 'date-time')]
    public string $created_at;
}
