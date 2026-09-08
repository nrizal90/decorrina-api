<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Body POST /api/bookings (booking manual admin, B3).
 */
#[OA\Schema(
    schema: 'BookingInput',
    title: 'BookingInput',
    description: 'Booking manual oleh admin. `payment_mode` dan `dp_minimum` TIDAK dikirim — '.
        'keduanya disalin backend dari pengaturan item yang dipilih.',
    required: ['item_id', 'guest_name', 'check_in', 'check_out', 'pax']
)]
class BookingInput
{
    #[OA\Property(
        property: 'item_id',
        type: 'integer',
        description: 'Item (kamar/paket) yang dipesan — bukan kategori. Harus milik tenant aktif dan berstatus Aktif.',
        example: 1
    )]
    public int $item_id;

    #[OA\Property(property: 'guest_name', type: 'string', maxLength: 255, example: 'Rina Pratiwi')]
    public string $guest_name;

    #[OA\Property(
        property: 'guest_phone',
        type: 'string',
        nullable: true,
        maxLength: 30,
        description: 'Dipakai sebagai kunci penggabungan tamu berulang dalam satu tenant. Tamu dengan nomor yang sama tidak dibuat ganda.',
        example: '081234567890'
    )]
    public ?string $guest_phone;

    #[OA\Property(property: 'guest_email', type: 'string', format: 'email', nullable: true, example: 'rina@example.com')]
    public ?string $guest_email;

    #[OA\Property(property: 'check_in', type: 'string', format: 'date', example: '2026-08-21')]
    public string $check_in;

    #[OA\Property(
        property: 'check_out',
        type: 'string',
        format: 'date',
        description: 'Harus setelah `check_in` — menginap minimal satu malam. Tanggal check-out boleh sama dengan check-in booking lain di item yang sama.',
        example: '2026-08-24'
    )]
    public string $check_out;

    #[OA\Property(
        property: 'pax',
        type: 'integer',
        minimum: 1,
        description: 'Wajib berada di antara `cap_min` dan `cap_max` milik item.',
        example: 10
    )]
    public int $pax;

    #[OA\Property(
        property: 'addons',
        type: 'array',
        description: 'Add-on opsional. Harga diambil dari master data saat ini lalu disimpan sebagai snapshot.',
        items: new OA\Items(
            required: ['addon_id'],
            properties: [
                new OA\Property(property: 'addon_id', type: 'integer', example: 1),
                new OA\Property(property: 'qty', type: 'integer', minimum: 1, maximum: 99, default: 1, example: 2),
            ]
        )
    )]
    public array $addons;

    #[OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Tamu minta kamar lantai bawah.')]
    public ?string $notes;
}
