<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Survey Lokasi (A5)
    |--------------------------------------------------------------------------
    |
    | Slot survey TIDAK disimpan sebagai baris template di database — slot
    | dibangkitkan dari sesi di bawah untuk setiap tanggal yang masih memenuhi
    | syarat, lalu dikurangi slot yang sudah terpakai. Kalau slot disimpan,
    | seseorang harus membuat ribuan baris kosong lebih dulu dan merawatnya.
    |
    | Angka-angka di sini berasal dari layar Settings (docs/05 "Sudah
    | dipastikan"), yang statusnya masih DEFAULT UI dan belum dikonfirmasi ke
    | klien. Ditaruh di config, bukan disebar di kode, supaya saat modul
    | Settings jadi nanti sumbernya tinggal dipindah ke sana.
    |
    */

    'sessions' => [
        ['code' => 'Pagi', 'start' => '09:00', 'end' => '11:00'],
        ['code' => 'Siang', 'start' => '13:00', 'end' => '15:00'],
    ],

    /*
    | Survey paling lambat H-7 sebelum check-in. Tim butuh jeda untuk menyiapkan
    | apa pun yang muncul dari hasil survey.
    */
    'deadline_days' => env('SURVEY_DEADLINE_DAYS', 7),

    /*
    | Jeda paling cepat dari hari ini. Survey untuk besok pagi tidak realistis
    | bagi tim lapangan.
    */
    'lead_days' => env('SURVEY_LEAD_DAYS', 2),

    /*
    | Berapa survey yang sanggup dikerjakan dalam satu sesi. Satu tim, satu
    | kunjungan — naikkan bila klien punya lebih dari satu tim survey.
    */
    'capacity_per_session' => env('SURVEY_CAPACITY_PER_SESSION', 1),

    /*
    | Batas jumlah slot yang ditawarkan ke pengunjung. Tanpa ini, check-in yang
    | masih berbulan-bulan lagi menghasilkan daftar puluhan slot yang justru
    | menyulitkan memilih.
    */
    'max_slots_offered' => 12,

];
