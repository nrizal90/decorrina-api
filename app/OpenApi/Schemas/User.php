<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Schema OpenAPI untuk payload user — cerminan App\Http\Resources\UserResource.
 * Kelas ini tidak berisi logika, hanya pembawa atribut swagger-php. Kalau
 * UserResource::toArray() berubah, sesuaikan properti di sini juga.
 */
#[OA\Schema(
    schema: 'User',
    title: 'User',
    description: 'Representasi user pada API. `permissions` adalah gabungan permission dari seluruh role — FE memakai ini untuk guard menu/tombol (bukan role, lihat dok 06 §9).'
)]
class User
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(property: 'name', type: 'string', example: 'Budi Santoso')]
    public string $name;

    #[OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi@example.com')]
    public string $email;

    #[OA\Property(
        property: 'tenant_id',
        type: 'integer',
        nullable: true,
        description: 'NULL untuk akun global (Customer & Superadmin Azatech).',
        example: null
    )]
    public ?int $tenant_id;

    #[OA\Property(
        property: 'status',
        type: 'string',
        enum: ['Aktif', 'Nonaktif'],
        description: 'Akun `Nonaktif` tidak bisa login (403) dan tokennya dicabut saat dinonaktifkan.',
        example: 'Aktif'
    )]
    public string $status;

    #[OA\Property(
        property: 'roles',
        type: 'array',
        items: new OA\Items(type: 'string'),
        example: ['customer']
    )]
    public array $roles;

    #[OA\Property(
        property: 'permissions',
        type: 'array',
        items: new OA\Items(type: 'string'),
        example: ['bookings:index', 'bookings:store']
    )]
    public array $permissions;

    #[OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-08-27T01:45:18.000000Z')]
    public string $created_at;
}
