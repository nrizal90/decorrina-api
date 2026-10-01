<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reschedule Booking (A13 / B3)
    |--------------------------------------------------------------------------
    |
    | Tamu (login) MENGAJUKAN dari Riwayat, admin menyetujui/menolak. Admin
    | juga bisa me-reschedule langsung dari papan Booking — aturan di bawah
    | hanya membatasi pengajuan tamu; admin cukup lolos cek ketersediaan &
    | kapasitas.
    |
    | Ini hanya DEFAULT. Nilai yang berlaku diatur admin di Settings >
    | Kebijakan Operasional (tabel tenant_settings, App\Support\TenantSettings).
    | `statuses` belum bisa diubah dari layar.
    |
    */

    /* Berapa kali tamu boleh reschedule satu booking (pengajuan yang DISETUJUI). */
    'quota' => 1,

    /* Pengajuan paling lambat H-n sebelum check-in AWAL. */
    'deadline_days' => 3,

    /* Tamu boleh mengubah jumlah malam? false = lama menginap harus sama. */
    'allow_change_nights' => false,

    /* Tamu boleh pindah kamar (item lain di villa yang sama)? */
    'allow_change_item' => false,

    /* Status booking yang boleh di-reschedule tamu. */
    'statuses' => [
        'Menunggu Pembayaran',
        'DP Dibayar',
        'Lunas',
    ],

];
