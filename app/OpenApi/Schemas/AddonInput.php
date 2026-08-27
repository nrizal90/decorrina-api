<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Body untuk POST/PUT add-on. Tautan dikirim sebagai dua daftar id terpisah
 * lalu digabung ke pivot polimorfik `addon_links`.
 */
#[OA\Schema(schema: 'AddonInput', title: 'AddonInput', required: ['name', 'price'])]
class AddonInput
{
    #[OA\Property(property: 'name', type: 'string', example: 'Extra Bed')]
    public string $name;

    #[OA\Property(property: 'price', type: 'integer', example: 150000)]
    public int $price;

    #[OA\Property(property: 'description', type: 'string', nullable: true)]
    public ?string $description;

    #[OA\Property(property: 'unit', type: 'string', nullable: true, example: 'unit')]
    public ?string $unit;

    #[OA\Property(property: 'deadline_days', type: 'integer', nullable: true, example: 4)]
    public ?int $deadline_days;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1, 2])]
    public array $category_ids;

    #[OA\Property(property: 'item_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [])]
    public array $item_ids;
}
