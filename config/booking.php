<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kode Booking
    |--------------------------------------------------------------------------
    |
    | Format: {PREFIX}-{TAHUN}-{URUT 5 digit}, mis. DCG-2026-00123.
    | Prefix diambil dari `tenants.booking_prefix`; nilai di bawah dipakai bila
    | tenant belum menyetelnya.
    |
    */

    'default_prefix' => env('BOOKING_PREFIX', 'DCG'),

    'sequence_padding' => 5,

];
