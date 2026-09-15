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

    /*
    |--------------------------------------------------------------------------
    | Batas Menginap (audit T-04)
    |--------------------------------------------------------------------------
    */

    'max_nights' => (int) env('BOOKING_MAX_NIGHTS', 30),

    'max_months_ahead' => (int) env('BOOKING_MAX_MONTHS_AHEAD', 12),

    /*
    |--------------------------------------------------------------------------
    | Booking Publik yang Menunggu Pembayaran (audit T-05)
    |--------------------------------------------------------------------------
    |
    | "Menunggu Pembayaran" memblokir kalender. Tanpa batas, satu skrip anonim
    | bisa memenuhi semua villa selamanya. Booking jalur customer yang tidak
    | dibayar dalam `pending_expiry_hours` dibatalkan oleh scheduler
    | (`bookings:expire-pending`), dan satu nomor telepon hanya boleh punya
    | `max_pending_per_phone` booking menunggu sekaligus.
    |
    */

    'pending_expiry_hours' => (int) env('BOOKING_PENDING_EXPIRY_HOURS', 24),

    'max_pending_per_phone' => (int) env('BOOKING_MAX_PENDING_PER_PHONE', 3),

];
