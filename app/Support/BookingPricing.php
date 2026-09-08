<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Item;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Perhitungan harga booking.
 *
 * Tarif dihitung PER MALAM, dan tiap malam dinilai sendiri sebagai weekday atau
 * weekend — booking Jumat–Senin bukan "3 x satu tarif", melainkan campuran.
 * Malam yang dihitung adalah malam MENGINAP, jadi tanggal check-out tidak ikut.
 *
 * Definisi weekend: malam Sabtu dan malam Minggu (yaitu tanggal menginap yang
 * jatuh pada Sabtu atau Minggu). Tarif hari libur nasional TIDAK dihitung
 * otomatis — sesuai catatan di ItemForm, itu diatur manual oleh admin.
 */
class BookingPricing
{
    /**
     * @return array{nights: int, weekday_nights: int, weekend_nights: int, subtotal: int}
     */
    public static function forStay(Item $item, string $checkIn, string $checkOut): array
    {
        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        $weekdayNights = 0;
        $weekendNights = 0;
        $subtotal = 0;

        // excludeEndDate: tamu tidak menginap pada malam tanggal check-out.
        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            if ($night->isSaturday() || $night->isSunday()) {
                $weekendNights++;
                $subtotal += $item->price_weekend;
            } else {
                $weekdayNights++;
                $subtotal += $item->price_weekday;
            }
        }

        return [
            'nights' => $weekdayNights + $weekendNights,
            'weekday_nights' => $weekdayNights,
            'weekend_nights' => $weekendNights,
            'subtotal' => $subtotal,
        ];
    }

    /**
     * Hitung baris add-on beserta subtotalnya.
     *
     * @param  array<int, array{addon_id: int, qty: int}>  $lines
     * @param  Collection<int, Addon>  $addons  addon terindeks id
     * @return array{lines: array<int, array<string, int>>, subtotal: int}
     */
    public static function forAddons(array $lines, $addons): array
    {
        $rows = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $addon = $addons[$line['addon_id']] ?? null;
            if (! $addon) {
                continue;
            }

            $qty = max(1, (int) ($line['qty'] ?? 1));
            $lineSubtotal = $addon->price * $qty;

            $rows[] = [
                'addon_id' => $addon->id,
                'qty' => $qty,
                // Snapshot: harga master boleh berubah nanti tanpa mengubah booking.
                'unit_price' => $addon->price,
                'subtotal' => $lineSubtotal,
            ];

            $subtotal += $lineSubtotal;
        }

        return ['lines' => $rows, 'subtotal' => $subtotal];
    }
}
