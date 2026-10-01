<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Item;
use App\Models\Reschedule;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reschedule booking — SATU implementasi untuk tamu (ajukan → admin
 * setujui) dan admin (langsung dari papan B3).
 *
 *  - Tamu: aturan per tenant dari Settings (TenantSettings: kuota, H-n, jumlah
 *    malam, ganti kamar; default config/reschedule.php) + ketersediaan + kapasitas.
 *  - Admin: cukup ketersediaan + kapasitas; booking final tidak bisa diubah.
 *  - Disetujui: harga dihitung ulang dengan BookingPricing, tanggal & harga
 *    booking ditimpa, survey yang belum terjadi dibatalkan.
 */
class Rescheduler
{
    /**
     * Rincian harga & ketersediaan tanggal baru, untuk layar sebelum mengirim.
     *
     * @return array<string, mixed>
     */
    public static function preview(Booking $booking, Item $item, string $checkIn, string $checkOut): array
    {
        $stay = BookingPricing::forStay($item, $checkIn, $checkOut);
        $newTotal = $stay['subtotal'] + $booking->subtotal_addons;

        return [
            'item' => ['id' => $item->id, 'name' => $item->name],
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            ...$stay,
            'old_total' => $booking->total,
            'new_total' => $newTotal,
            'difference' => $newTotal - $booking->total,
            // Selisih positif booking Lunas = tagihan baru; booking DP ikut pelunasan.
            'difference_due' => $booking->status === Booking::STATUS_LUNAS ? max(0, $newTotal - $booking->total) : 0,
            'available' => BookingAvailability::isAvailable($item->id, $checkIn, $checkOut, $booking->id),
        ];
    }

    /** Aturan yang ditampilkan ke tamu (dibaca dari config). */
    public static function policy(Booking $booking): array
    {
        $used = $booking->reschedules()
            ->where('source', 'customer')
            ->where('status', Reschedule::STATUS_DISETUJUI)
            ->count();

        return [
            'quota' => (int) TenantSettings::get('reschedule.quota', $booking->tenant_id),
            'used' => $used,
            'deadline' => self::deadlineFor($booking)->toDateString(),
            'allow_change_nights' => (bool) TenantSettings::get('reschedule.allow_change_nights', $booking->tenant_id),
            'allow_change_item' => (bool) TenantSettings::get('reschedule.allow_change_item', $booking->tenant_id),
        ];
    }

    public static function deadlineFor(Booking $booking): Carbon
    {
        return $booking->check_in->copy()->startOfDay()->subDays((int) TenantSettings::get('reschedule.deadline_days', $booking->tenant_id));
    }

    /**
     * Alasan booking ini belum bisa diajukan reschedule oleh tamu (tanpa
     * melihat tanggal baru), null bila boleh. Dipakai untuk menyalakan tombol.
     */
    public static function customerBlocker(Booking $booking): ?string
    {
        if (! in_array($booking->status, config('reschedule.statuses'), true)) {
            return "Booking berstatus \"{$booking->status}\" tidak bisa di-reschedule.";
        }

        if ($booking->reschedules()->where('status', Reschedule::STATUS_MENUNGGU)->exists()) {
            return 'Masih ada pengajuan reschedule yang menunggu persetujuan admin.';
        }

        $policy = self::policy($booking);
        if ($policy['used'] >= $policy['quota']) {
            return 'Kuota reschedule untuk booking ini sudah habis. Silakan hubungi admin.';
        }

        if (Carbon::today()->greaterThan(self::deadlineFor($booking))) {
            return 'Reschedule paling lambat '.self::deadlineFor($booking)->toDateString()
                .' (H-'.TenantSettings::get('reschedule.deadline_days', $booking->tenant_id).' sebelum check-in). Silakan hubungi admin.';
        }

        return null;
    }

    /** Pesan error untuk pengajuan tamu, null bila boleh diajukan. */
    public static function customerRejection(Booking $booking, Item $item, string $checkIn, string $checkOut): ?string
    {
        if ($blocker = self::customerBlocker($booking)) {
            return $blocker;
        }

        if (! TenantSettings::get('reschedule.allow_change_item', $booking->tenant_id) && $item->id !== $booking->item_id) {
            return 'Reschedule hanya bisa memindahkan tanggal, bukan kamar.';
        }

        $nights = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
        if (! TenantSettings::get('reschedule.allow_change_nights', $booking->tenant_id) && (int) $nights !== $booking->nights) {
            return "Lama menginap harus tetap {$booking->nights} malam.";
        }

        if (Carbon::parse($checkIn)->lessThanOrEqualTo(Carbon::today())) {
            return 'Tanggal check-in baru harus setelah hari ini.';
        }

        if ($nights > (int) config('booking.max_nights')) {
            return 'Lama menginap maksimal '.config('booking.max_nights').' malam.';
        }

        return self::stayRejection($booking, $item, $checkIn, $checkOut);
    }

    /** Pesan error untuk reschedule oleh admin, null bila boleh. */
    public static function adminRejection(Booking $booking, Item $item, string $checkIn, string $checkOut): ?string
    {
        if (in_array($booking->status, [Booking::STATUS_SELESAI, Booking::STATUS_DIBATALKAN], true)) {
            return "Booking berstatus \"{$booking->status}\" sudah final dan tidak bisa di-reschedule.";
        }

        return self::stayRejection($booking, $item, $checkIn, $checkOut);
    }

    /** Aturan bersama: tanggal valid, kamar aktif di villa yang sama, kapasitas, kosong. */
    private static function stayRejection(Booking $booking, Item $item, string $checkIn, string $checkOut): ?string
    {
        if ($checkOut <= $checkIn) {
            return 'Tanggal check-out harus setelah check-in.';
        }

        if ($item->id !== $booking->item_id) {
            $sameVilla = $item->category_id === $booking->item?->category_id;

            if (! $sameVilla || $item->status !== 'Aktif') {
                return 'Kamar tujuan harus kamar aktif di villa yang sama.';
            }

            if ($booking->pax < $item->cap_min || $booking->pax > $item->cap_max) {
                return "Kapasitas {$item->name} {$item->cap_min}–{$item->cap_max} orang, booking ini {$booking->pax} orang.";
            }
        }

        $conflicts = BookingAvailability::conflicts($item->id, $checkIn, $checkOut, $booking->id);
        if ($conflicts->isNotEmpty()) {
            return 'Tanggal tersebut sudah terisi booking lain: '
                .$conflicts->map(fn ($c) => $c->kode_booking)->implode(', ').'.';
        }

        return null;
    }

    /** Tamu mengajukan; booking belum berubah sampai admin menyetujui. */
    public static function request(Booking $booking, Item $item, string $checkIn, string $checkOut, ?string $reason, User $user): Reschedule
    {
        $preview = self::preview($booking, $item, $checkIn, $checkOut);

        return self::newRecord($booking, $item, $checkIn, $checkOut, $preview['new_total'], [
            'source' => 'customer',
            'status' => Reschedule::STATUS_MENUNGGU,
            'reason' => $reason,
            'requested_by' => $user->id,
        ]);
    }

    /** Admin me-reschedule langsung: tercatat sekaligus disetujui. */
    public static function applyByAdmin(Booking $booking, Item $item, string $checkIn, string $checkOut, ?string $note, User $admin): Reschedule
    {
        return DB::transaction(function () use ($booking, $item, $checkIn, $checkOut, $note, $admin) {
            $record = self::newRecord($booking, $item, $checkIn, $checkOut, 0, [
                'source' => 'admin',
                'status' => Reschedule::STATUS_MENUNGGU,
                'admin_note' => $note,
                'requested_by' => $admin->id,
            ]);

            return self::approve($record, $admin, $note);
        });
    }

    /**
     * Setujui: harga dihitung ULANG saat ini (bisa berubah sejak diajukan),
     * booking ditimpa, survey yang belum terjadi dibatalkan.
     */
    public static function approve(Reschedule $record, User $admin, ?string $note = null): Reschedule
    {
        return DB::transaction(function () use ($record, $admin, $note) {
            $booking = $record->booking()->lockForUpdate()->firstOrFail();
            $item = $record->newItem;
            $checkIn = $record->new_check_in->toDateString();
            $checkOut = $record->new_check_out->toDateString();

            $stay = BookingPricing::forStay($item, $checkIn, $checkOut);
            $newTotal = $stay['subtotal'] + $booking->subtotal_addons;

            $booking->update([
                'item_id' => $item->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $stay['nights'],
                'price_weekday' => $item->price_weekday,
                'price_weekend' => $item->price_weekend,
                'price_holiday' => $item->price_holiday,
                'subtotal_item' => $stay['subtotal'],
                'total' => $newTotal,
            ]);

            Survey::cancelForBookings([$booking->id]);

            $record->update([
                'status' => Reschedule::STATUS_DISETUJUI,
                'new_total' => $newTotal,
                'difference' => $newTotal - $record->old_total,
                'admin_note' => $note ?? $record->admin_note,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);

            return $record->fresh(['booking', 'oldItem', 'newItem']);
        });
    }

    public static function reject(Reschedule $record, User $admin, ?string $note): Reschedule
    {
        $record->update([
            'status' => Reschedule::STATUS_DITOLAK,
            'admin_note' => $note,
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ]);

        return $record->fresh(['booking', 'oldItem', 'newItem']);
    }

    /** @param array<string, mixed> $extra */
    private static function newRecord(Booking $booking, Item $item, string $checkIn, string $checkOut, int $newTotal, array $extra): Reschedule
    {
        // tenant_id eksplisit: tamu (tenant_id NULL) tidak punya tenant aktif.
        return Reschedule::forceCreate([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'old_item_id' => $booking->item_id,
            'old_check_in' => $booking->check_in->toDateString(),
            'old_check_out' => $booking->check_out->toDateString(),
            'old_total' => $booking->total,
            'new_item_id' => $item->id,
            'new_check_in' => $checkIn,
            'new_check_out' => $checkOut,
            'new_total' => $newTotal,
            'difference' => $newTotal - $booking->total,
            ...$extra,
        ]);
    }
}
