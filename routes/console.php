<?php

use App\Models\Booking;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
| Audit T-05: booking jalur customer yang tak kunjung dibayar dibatalkan
| otomatis agar kalender tidak terblokir selamanya oleh pemesan anonim.
| Booking admin (source=admin) tidak disentuh — itu kesepakatan manual.
| Dari CLI tidak ada tenant terikat, jadi scope tenant dilepas eksplisit.
*/
Artisan::command('bookings:expire-pending', function () {
    $hours = (int) config('booking.pending_expiry_hours');

    $count = Booking::withoutGlobalScopes()
        ->where('source', 'customer')
        ->where('status', Booking::STATUS_MENUNGGU)
        ->where('created_at', '<', now()->subHours($hours))
        ->update(['status' => Booking::STATUS_DIBATALKAN]);

    if ($count > 0) {
        Log::info('Booking menunggu pembayaran kedaluwarsa dibatalkan', ['count' => $count, 'hours' => $hours]);
    }

    $this->info("{$count} booking dibatalkan (lebih dari {$hours} jam tanpa pembayaran).");
})->purpose('Batalkan booking publik yang lewat batas waktu pembayaran');

Schedule::command('bookings:expire-pending')->hourly();
