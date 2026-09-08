<?php

namespace App\Support;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pengecekan ketersediaan tanggal (A4).
 *
 * Satu item hanya bisa ditempati satu booking pada rentang tanggal yang sama.
 * Booking berstatus `Dibatalkan` melepaskan tanggalnya kembali.
 */
class BookingAvailability
{
    /**
     * Apakah item bebas pada rentang ini?
     *
     * `$ignoreBookingId` dipakai saat reschedule/ubah tanggal, supaya booking
     * yang sedang diedit tidak dihitung bentrok dengan dirinya sendiri.
     */
    public static function isAvailable(
        int $itemId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId = null,
    ): bool {
        return ! self::conflictsQuery($itemId, $checkIn, $checkOut, $ignoreBookingId)->exists();
    }

    /**
     * Booking yang menghalangi — dipakai untuk memberi tahu tanggal mana yang
     * bentrok, bukan sekadar menolak.
     *
     * @return Collection<int, Booking>
     */
    public static function conflicts(
        int $itemId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId = null,
    ) {
        return self::conflictsQuery($itemId, $checkIn, $checkOut, $ignoreBookingId)
            ->orderBy('check_in')
            ->get(['id', 'kode_booking', 'check_in', 'check_out', 'status']);
    }

    private static function conflictsQuery(
        int $itemId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreBookingId,
    ) {
        return Booking::query()
            ->where('item_id', $itemId)
            ->blocking()
            ->overlapping($checkIn, $checkOut)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId));
    }
}
