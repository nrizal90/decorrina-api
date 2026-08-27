<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ErrorResponse',
    title: 'ErrorResponse',
    description: 'Envelope response error standar.'
)]
class ErrorResponse
{
    #[OA\Property(property: 'success', type: 'boolean', example: false)]
    public bool $success;

    #[OA\Property(property: 'message', type: 'string', example: 'Terjadi kesalahan.')]
    public string $message;

    #[OA\Property(
        property: 'errors',
        type: 'object',
        nullable: true,
        description: 'Peta field -> daftar pesan (hanya untuk error validasi).',
        example: ['email' => ['Email wajib diisi.']]
    )]
    public ?object $errors;
}
