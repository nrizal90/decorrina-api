<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Cerminan BookingResource.
 */
#[OA\Schema(
    schema: 'Booking',
    title: 'Booking',
    description: 'Pemesanan sebuah item (kamar/paket) pada rentang tanggal tertentu. '.
        'Seluruh nilai harga adalah SNAPSHOT saat booking dibuat — mengubah harga di master data '.
        'tidak mengubah booking yang sudah ada.'
)]
class Booking
{
    #[OA\Property(property: 'id', type: 'integer', example: 1)]
    public int $id;

    #[OA\Property(
        property: 'kode_booking',
        type: 'string',
        description: 'Format `{PREFIX}-{TAHUN}-{URUT 5 digit}`. Prefix diambil dari `tenants.booking_prefix`, nomor urut di-reset tiap tahun per tenant.',
        example: 'DCG-2026-00123'
    )]
    public string $kode_booking;

    #[OA\Property(
        property: 'guest',
        type: 'object',
        description: 'Tamu yang menginap. Dibuat otomatis dari data booking, digabung berdasar nomor telepon dalam satu tenant.',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'Rina Pratiwi'),
            new OA\Property(property: 'phone', type: 'string', nullable: true, example: '081234567890'),
            new OA\Property(property: 'email', type: 'string', nullable: true, example: 'rina@example.com'),
        ]
    )]
    public object $guest;

    #[OA\Property(
        property: 'item',
        type: 'object',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'Kamar Superior'),
        ]
    )]
    public object $item;

    #[OA\Property(
        property: 'villa',
        type: 'string',
        nullable: true,
        description: 'Nama kategori induk item, diratakan untuk kolom VILLA di layar B3.',
        example: 'Villa De Corrinna'
    )]
    public ?string $villa;

    #[OA\Property(property: 'check_in', type: 'string', format: 'date', example: '2026-08-21')]
    public string $check_in;

    #[OA\Property(property: 'check_out', type: 'string', format: 'date', example: '2026-08-24')]
    public string $check_out;

    #[OA\Property(
        property: 'nights',
        type: 'integer',
        description: 'Jumlah malam menginap. Malam tanggal check-out tidak dihitung.',
        example: 3
    )]
    public int $nights;

    #[OA\Property(property: 'pax', type: 'integer', description: 'Jumlah tamu, wajib berada dalam rentang kapasitas item.', example: 10)]
    public int $pax;

    #[OA\Property(
        property: 'subtotal_item',
        type: 'integer',
        description: 'Tarif kamar, dihitung per malam: tiap malam dinilai weekday atau weekend sendiri-sendiri.',
        example: 13600000
    )]
    public int $subtotal_item;

    #[OA\Property(property: 'subtotal_addons', type: 'integer', example: 300000)]
    public int $subtotal_addons;

    #[OA\Property(property: 'total', type: 'integer', example: 13900000)]
    public int $total;

    #[OA\Property(
        property: 'payment_mode',
        type: 'string',
        enum: ['Full Payment', 'DP + Pelunasan', 'Keduanya — customer memilih'],
        description: 'Disalin dari pengaturan item saat booking dibuat (keputusan 2026-09-08), bukan dipilih bebas oleh admin.',
        example: 'Full Payment'
    )]
    public string $payment_mode;

    #[OA\Property(property: 'dp_minimum', type: 'integer', nullable: true, example: 2000000)]
    public ?int $dp_minimum;

    #[OA\Property(
        property: 'status',
        type: 'string',
        enum: ['Menunggu Pembayaran', 'DP Dibayar', 'Lunas', 'Selesai', 'Dibatalkan'],
        example: 'Menunggu Pembayaran'
    )]
    public string $status;

    #[OA\Property(
        property: 'allowed_transitions',
        type: 'array',
        items: new OA\Items(type: 'string'),
        description: 'Status tujuan yang sah dari status sekarang. FE menyusun pilihan ubah status dari sini, jadi aturan state machine tidak perlu disalin ulang di frontend. Kosong berarti status final.',
        example: ['DP Dibayar', 'Lunas', 'Dibatalkan']
    )]
    public array $allowed_transitions;

    #[OA\Property(
        property: 'source',
        type: 'string',
        enum: ['customer', 'admin'],
        description: '`customer` = alur publik A4–A10, `admin` = booking manual B3.',
        example: 'admin'
    )]
    public string $source;

    #[OA\Property(property: 'notes', type: 'string', nullable: true)]
    public ?string $notes;

    #[OA\Property(
        property: 'addons',
        type: 'array',
        description: 'Hanya disertakan pada endpoint detail. Harga per baris juga snapshot.',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'addon_id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'Extra Bed'),
                new OA\Property(property: 'qty', type: 'integer', example: 2),
                new OA\Property(property: 'unit_price', type: 'integer', example: 150000),
                new OA\Property(property: 'subtotal', type: 'integer', example: 300000),
            ]
        )
    )]
    public array $addons;

    #[OA\Property(property: 'created_at', type: 'string', format: 'date-time')]
    public string $created_at;
}
