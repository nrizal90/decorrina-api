<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Item;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Agregat untuk Laporan & Analitik (B10, Fase 8).
 *
 * Dua keputusan klien (2026-09-10) yang menentukan angkanya:
 *
 *  1. OKUPANSI dihitung dari booking yang MENGHALANGI KALENDER — semua status
 *     kecuali Dibatalkan, termasuk yang masih menunggu pembayaran. Kamar yang
 *     tidak bisa dijual ke orang lain berarti terisi, dibayar atau belum.
 *  2. PENDAPATAN hanya dari booking yang uangnya (setidaknya sebagian) sudah
 *     masuk: DP Dibayar, Lunas, Selesai. Yang menunggu pembayaran belum
 *     menghasilkan apa-apa.
 *
 * Catatan jujur soal "pendapatan": sampai modul pembayaran/ledger ada (Fase 4
 * & 7), angkanya adalah NILAI booking terkonfirmasi, bukan kas yang benar-benar
 * diterima. Booking berstatus DP dihitung penuh, padahal baru DP-nya yang
 * masuk. Layar menyebutkan ini.
 */
class Reports
{
    public const REVENUE_STATUSES = [
        Booking::STATUS_DP,
        Booking::STATUS_LUNAS,
        Booking::STATUS_SELESAI,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function occupancy(Carbon $from, Carbon $to): array
    {
        // Jumlah MALAM di jendela: [from, to) — malam tanggal `to` tidak ikut.
        $nights = max(0, $from->diffInDays($to));

        $items = Item::with('category')->where('status', 'Aktif')->orderBy('category_id')->orderBy('name')->get();

        $bookings = Booking::query()
            ->whereIn('item_id', $items->pluck('id'))
            ->blocking()
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get(['item_id', 'check_in', 'check_out']);

        // Malam terisi per item, dipotong ke jendela. Array biasa, bukan
        // Collection: `$collection[$key]++` tidak mengubah apa pun di Laravel.
        $filledByItem = $items->mapWithKeys(fn ($item) => [$item->id => 0])->all();
        // Berapa item terisi pada tiap malam — untuk grafik tren.
        $occupiedPerNight = [];

        foreach ($bookings as $booking) {
            foreach (self::nightsWithin($booking, $from, $to) as $night) {
                $filledByItem[$booking->item_id]++;
                $occupiedPerNight[$night] = ($occupiedPerNight[$night] ?? 0) + 1;
            }
        }

        $rows = $items->map(fn ($item) => [
            'item_id' => $item->id,
            'item' => $item->name,
            'villa' => $item->category?->name,
            'category_id' => $item->category_id,
            'filled_nights' => $filledByItem[$item->id],
            'available_nights' => $nights,
            'pct' => self::pct($filledByItem[$item->id], $nights),
        ]);

        $perVilla = $rows->groupBy('category_id')->map(function (Collection $group) {
            $filled = $group->sum('filled_nights');
            $available = $group->sum('available_nights');

            return [
                'category_id' => $group->first()['category_id'],
                'villa' => $group->first()['villa'],
                'filled_nights' => $filled,
                'available_nights' => $available,
                'pct' => self::pct($filled, $available),
            ];
        })->values();

        $trend = [];
        $activeCount = $items->count();
        if ($nights > 0) {
            foreach (CarbonPeriod::create($from, $to)->excludeEndDate() as $night) {
                $key = $night->toDateString();
                $trend[] = [
                    'date' => $key,
                    'occupied' => $occupiedPerNight[$key] ?? 0,
                    'pct' => self::pct($occupiedPerNight[$key] ?? 0, $activeCount),
                ];
            }
        }

        return [
            'overall_pct' => self::pct($rows->sum('filled_nights'), $rows->sum('available_nights')),
            'per_villa' => $perVilla,
            'trend' => $trend,
            'rows' => $rows->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function crm(Carbon $from, Carbon $to): array
    {
        $totalGuests = Guest::count();

        // "Tamu baru bulan ini" mengikuti bulan kalender berjalan, bukan
        // jendela laporan — itulah yang dimaksud labelnya.
        $newThisMonth = Guest::whereBetween('created_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->count();

        $avgVehicles = Booking::query()
            ->blocking()
            ->whereNotNull('vehicle_count')
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->avg('vehicle_count');

        // Persentase asal daerah dihitung atas tamu yang MENGISI asal daerah,
        // bukan seluruh tamu — booking manual admin sering mengosongkannya, dan
        // ikut menghitung mereka hanya mengecilkan semua angka.
        $withOrigin = Guest::whereNotNull('origin')->where('origin', '!=', '')->count();
        $origins = Guest::query()
            ->selectRaw('origin, COUNT(*) as guests')
            ->whereNotNull('origin')
            ->where('origin', '!=', '')
            ->groupBy('origin')
            ->orderByDesc('guests')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'origin' => $row->origin,
                'guests' => (int) $row->guests,
                'pct' => self::pct((int) $row->guests, $withOrigin),
            ]);

        $typed = Guest::whereIn('guest_type', Guest::TYPES)->count();
        $pribadi = Guest::where('guest_type', 'Pribadi')->count();

        return [
            'total_guests' => $totalGuests,
            'new_this_month' => $newThisMonth,
            'avg_vehicle_count' => $avgVehicles === null ? null : round((float) $avgVehicles, 1),
            'origins' => $origins,
            'origins_basis' => $withOrigin,
            'guest_type' => [
                'pribadi_pct' => self::pct($pribadi, $typed),
                'instansi_pct' => self::pct($typed - $pribadi, $typed),
                'basis' => $typed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function revenue(Carbon $from, Carbon $to): array
    {
        $bookings = Booking::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->get(['subtotal_item', 'subtotal_addons']);

        $villa = (int) $bookings->sum('subtotal_item');
        $addons = (int) $bookings->sum('subtotal_addons');
        $grand = $villa + $addons;

        return [
            'total' => $grand,
            'rows' => [
                [
                    'category' => 'Booking Villa',
                    'bookings' => $bookings->count(),
                    'total' => $villa,
                    'pct' => self::pct($villa, $grand),
                    'available' => true,
                ],
                [
                    'category' => 'Add-on',
                    'bookings' => $bookings->where('subtotal_addons', '>', 0)->count(),
                    'total' => $addons,
                    'pct' => self::pct($addons, $grand),
                    'available' => true,
                ],
                [
                    // Modul catering belum ada (keputusan klien: tampilkan 0,
                    // bukan disembunyikan, supaya barisnya tidak "hilang").
                    'category' => 'Catering',
                    'bookings' => 0,
                    'total' => 0,
                    'pct' => 0,
                    'available' => false,
                ],
            ],
        ];
    }

    /** Persentase bulat; 0 bila penyebutnya nol, bukan pembagian dengan nol. */
    private static function pct(int|float $part, int|float $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    /** @return array<int, string> */
    private static function nightsWithin(Booking $booking, Carbon $from, Carbon $to): array
    {
        $nights = [];

        foreach (CarbonPeriod::create($booking->check_in, $booking->check_out)->excludeEndDate() as $night) {
            if ($night->greaterThanOrEqualTo($from) && $night->lessThan($to)) {
                $nights[] = $night->toDateString();
            }
        }

        return $nights;
    }
}
