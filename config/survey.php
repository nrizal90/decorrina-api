<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Survey Lokasi (A5 & B4)
    |--------------------------------------------------------------------------
    |
    | CR-07 (klarifikasi klien 27 Sep 2026): tidak ada slot baku lagi. Tamu
    | memilih tanggal, lalu jam mulai & jam selesai sendiri. Jam yang boleh
    | dipilih bergantung pada keadaan VILLA pada tanggal itu, dan satu villa
    | hanya bisa disurvei satu rombongan pada satu waktu (jam tidak boleh
    | bertumpuk). Lihat App\Support\SurveySlots.
    |
    */

    /* Jam operasional survey bila villa kosong pada tanggal itu. */
    'window_empty' => ['start' => '07:00', 'end' => '20:00'],

    /*
    | Bila villa ada tamu (termasuk hari check-in & check-out tamu), survey
    | hanya di jam ini supaya tidak mengganggu.
    */
    'window_occupied' => ['start' => '12:00', 'end' => '14:00'],

    /* Survey paling lambat H-1 sebelum check-in. */
    'deadline_days' => env('SURVEY_DEADLINE_DAYS', 1),

    /*
    | Jeda paling cepat dari hari ini untuk jalur customer. Survey untuk
    | besok pagi tidak realistis bagi tim lapangan. Admin tidak dibatasi.
    */
    'lead_days' => env('SURVEY_LEAD_DAYS', 2),

];
