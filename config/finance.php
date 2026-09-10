<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bagi Hasil (Joint Venture)
    |--------------------------------------------------------------------------
    |
    | Rasio pembagian pendapatan per villa, dikunci pada slug kategori.
    |
    | SEMENTARA — keputusan klien 2026-09-10: modul joint venture belum ada,
    | jadi 70:30 untuk Cendana Wangi dipatok di sini alih-alih disimpan per
    | perjanjian di database. Begitu modulnya ada, sumbernya tinggal dipindah
    | dan pemakainya (`App\Support\Ledger::profitShare`) tidak perlu berubah.
    |
    | Villa yang tidak ada di daftar ini tidak punya skema bagi hasil.
    |
    */

    'profit_share' => [
        'villa-cendana-wangi' => [
            'owner_pct' => 70,
            'operator_pct' => 30,
            'owner_label' => 'Owner',
            'operator_label' => 'Pengelola',
        ],
    ],

    /*
    | Kategori entri buku kas yang ditawarkan di form "Catat Transaksi".
    | Kolomnya string bebas — daftar ini hanya saran, bukan enum DB — supaya
    | admin bisa menulis kategori baru tanpa migrasi.
    */
    'categories' => [
        'Pemasukan' => ['Booking Villa', 'Add-on', 'Catering', 'Lain-lain'],
        'Pengeluaran' => ['Operasional', 'Gaji', 'Perawatan', 'Listrik & Air', 'Pemasaran', 'Lain-lain'],
    ],

];
