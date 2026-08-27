<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan CategoryResource. Pembawa atribut swagger-php, tanpa logika.
 */
#[OA\Schema(
    schema: 'Category',
    title: 'Category',
    description: 'Kategori = "villa" di katalog publik, sekaligus induk item di admin.'
)]
class Category
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'name', type: 'string', example: 'Villa De Corrinna')]
    public string $name;

    #[OA\Property(property: 'slug', type: 'string', description: 'Dipakai /api/villas/{slug}. Unik per tenant.', example: 'villa-de-corrinna')]
    public string $slug;

    #[OA\Property(property: 'icon', type: 'string', nullable: true, description: 'Nama ikon lucide.', example: 'house')]
    public ?string $icon;

    #[OA\Property(property: 'description', type: 'string', nullable: true, example: 'Rumah utama kami — sanctuary yang tenang di antara pepohonan pinus.')]
    public ?string $description;

    #[OA\Property(property: 'tagline', type: 'string', nullable: true, example: 'Rumah utama dengan taman luas dan kolam privat.')]
    public ?string $tagline;

    #[OA\Property(property: 'location', type: 'string', nullable: true, example: 'Kawasan Gerbang Gunungsari, Pamijahan, Bogor')]
    public ?string $location;

    #[OA\Property(property: 'status', type: 'string', enum: ['Aktif', 'Nonaktif'], example: 'Aktif')]
    public string $status;

    #[OA\Property(property: 'items_count', type: 'integer', description: 'Kolom "Jumlah Item" di B4 — hanya ada pada endpoint list.', example: 12)]
    public int $items_count;

    #[OA\Property(property: 'facilities', type: 'array', items: new OA\Items(ref: '#/components/schemas/Facility'))]
    public array $facilities;

    #[OA\Property(property: 'created_at', type: 'string', format: 'date-time')]
    public string $created_at;
}
