<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Definisi OpenAPI global untuk De'Corrinna API.
 *
 * Kelas ini tidak berisi logika — hanya pembawa atribut swagger-php yang
 * dibaca l5-swagger untuk menghasilkan halaman dokumentasi di
 * /api/documentation. Anotasi tiap endpoint diletakkan di controllernya.
 */
#[OA\Info(
    version: '1.0.0',
    title: "De'Corrinna API",
    description: 'REST API backend De\'Corrinna (Laravel 12 + PostgreSQL). Autentikasi memakai Bearer token (Laravel Sanctum).',
    contact: new OA\Contact(name: 'Tim Azatech', email: 'dev@azatech.id')
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Server lokal (dev)'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum',
    description: 'Token pribadi dari Laravel Sanctum. Kirim sebagai header: Authorization: Bearer {token}'
)]
#[OA\Tag(name: 'System', description: 'Health check & status sistem')]
#[OA\Tag(name: 'Auth', description: 'Registrasi, login, logout, profil (Fase 1)')]
class OpenApiSpec
{
}
