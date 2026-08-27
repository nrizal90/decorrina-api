<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SuccessResponse',
    title: 'SuccessResponse',
    description: 'Envelope response sukses standar.'
)]
class SuccessResponse
{
    #[OA\Property(property: 'success', type: 'boolean', example: true)]
    public bool $success;

    #[OA\Property(property: 'message', type: 'string', example: 'OK')]
    public string $message;

    #[OA\Property(property: 'data', type: 'object', nullable: true)]
    public mixed $data;
}
