<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginationMeta',
    title: 'PaginationMeta',
    description: 'Meta pagination standar untuk response terpaginasi.'
)]
class PaginationMeta
{
    #[OA\Property(property: 'current_page', type: 'integer', example: 1)]
    public int $currentPage;

    #[OA\Property(property: 'per_page', type: 'integer', example: 15)]
    public int $perPage;

    #[OA\Property(property: 'total', type: 'integer', example: 42)]
    public int $total;

    #[OA\Property(property: 'last_page', type: 'integer', example: 3)]
    public int $lastPage;

    #[OA\Property(property: 'from', type: 'integer', nullable: true, example: 1)]
    public ?int $from;

    #[OA\Property(property: 'to', type: 'integer', nullable: true, example: 15)]
    public ?int $to;
}
