<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Body untuk POST/PUT item. Cerminan StoreItemRequest/UpdateItemRequest.
 */
#[OA\Schema(
    schema: 'ItemInput',
    title: 'ItemInput',
    required: ['category_id', 'name', 'cap_min', 'cap_max', 'price_weekday', 'price_weekend', 'payment_mode']
)]
class ItemInput
{
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

    #[OA\Property(property: 'cap_max', type: 'integer', description: 'Tidak boleh lebih kecil dari cap_min.', example: 12)]
    public int $cap_max;

    #[OA\Property(property: 'price_weekday', type: 'integer', example: 4000000)]
    public int $price_weekday;

    #[OA\Property(property: 'price_weekend', type: 'integer', example: 4800000)]
    public int $price_weekend;

    #[OA\Property(property: 'payment_mode', type: 'string', enum: ['Full Payment', 'DP + Pelunasan', 'Keduanya — customer memilih'], example: 'Full Payment')]
    public string $payment_mode;

    #[OA\Property(
        property: 'dp_minimum',
        type: 'integer',
        nullable: true,
        description: 'Wajib bila payment_mode = "DP + Pelunasan" atau "Keduanya — customer memilih".',
        example: 2000000
    )]
    public ?int $dp_minimum;

    #[OA\Property(property: 'requires_survey', type: 'boolean', example: false)]
    public bool $requires_survey;

    #[OA\Property(
        property: 'status',
        type: 'string',
        enum: ['Aktif', 'Nonaktif'],
        description: 'Bila tidak dikirim, item tersimpan Nonaktif — inilah tombol "Simpan Draft".',
        example: 'Aktif'
    )]
    public string $status;
}
