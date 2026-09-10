<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Angka untuk Dashboard admin (B1, Fase 8).
 *
 * Semua dihitung dari data yang sudah ada — booking, survey, buku kas, dan
 * okupansi dari `Reports`. Tidak ada tabel audit log: "Aktivitas Terbaru"
 * diturunkan dari kejadian yang memang tercatat (booking dibuat, survey
 * dijadwalkan, pemasukan masuk buku kas, booking selesai).
 *
 * Setiap KPI membawa pembandingnya (kemarin / bulan lalu / 30 hari sebelumnya)
 * supaya panah tren di kartu punya arti, bukan hiasan.
 */
class Dashboard
{
    private const TREND_DAYS = 30;

    private const ACTIVITY_LIMIT = 6;

    /**
     * @return array<string, mixed>
     */
    public static function build(User $viewer): array
    {
        $today = Carbon::today();

        return [
            'bookings_today' => self::bookingsToday($today),
            'occupancy' => self::occupancy($today),
            // Pendapatan adalah data keuangan: Staff punya reports:view tapi
            // sengaja tidak punya ledger:* (seeder). Kartunya kosong untuk mereka.
            'revenue' => $viewer->can('ledger:index') ? self::revenue($today) : null,
            'pending' => self::pending(),
            'trend' => self::bookingTrend($today),
            'activities' => self::activities(),
        ];
    }

    /** @return array{value: int, previous: int} */
    private static function bookingsToday(Carbon $today): array
    {
        $count = fn (Carbon $day) => Booking::whereDate('created_at', $day->toDateString())->count();

        return [
            'value' => $count($today),
            'previous' => $count($today->copy()->subDay()),
        ];
    }

    /** Okupansi 30 hari terakhir vs 30 hari sebelumnya. @return array{value: int, previous: int} */
    private static function occupancy(Carbon $today): array
    {
        $to = $today->copy()->addDay(); // eksklusif: malam ini masih ikut
        $from = $to->copy()->subDays(self::TREND_DAYS);
        $prevFrom = $from->copy()->subDays(self::TREND_DAYS);

        return [
            'value' => Reports::occupancy($from, $to)['overall_pct'],
            'previous' => Reports::occupancy($prevFrom, $from)['overall_pct'],
        ];
    }

    /** Pemasukan buku kas bulan ini vs bulan lalu. @return array{value: int, previous: int} */
    private static function revenue(Carbon $today): array
    {
        $sum = fn (Carbon $month) => (int) LedgerEntry::income()
            ->between($month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString())
            ->sum('amount');

        return [
            'value' => $sum($today),
            'previous' => $sum($today->copy()->subMonthNoOverflow()),
        ];
    }

    /**
     * "Perlu konfirmasi" = hal yang menunggu tindakan admin: booking yang belum
     * dibayar dan survey yang sudah lewat tapi belum dilaporkan.
     *
     * @return array{value: int, bookings: int, surveys: int}
     */
    private static function pending(): array
    {
        $bookings = Booking::where('status', Booking::STATUS_MENUNGGU)->count();
        $surveys = Survey::where('status', Survey::STATUS_MENUNGGU_LAPORAN)->count();

        return ['value' => $bookings + $surveys, 'bookings' => $bookings, 'surveys' => $surveys];
    }

    /**
     * Booking DIBUAT per hari, 30 hari terakhir — ini tren permintaan, beda
     * dari tren okupansi di laporan yang menghitung malam terisi.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private static function bookingTrend(Carbon $today): array
    {
        $from = $today->copy()->subDays(self::TREND_DAYS - 1);

        $perDay = Booking::query()
            ->whereDate('created_at', '>=', $from->toDateString())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];
        foreach (CarbonPeriod::create($from, $today) as $day) {
            $key = $day->toDateString();
            $trend[] = ['date' => $key, 'count' => (int) ($perDay[$key] ?? 0)];
        }

        return $trend;
    }

    /**
     * Kejadian terbaru dari tabel-tabel yang ada, digabung lalu diambil yang
     * paling baru. Bukan audit log — tidak mencatat siapa, hanya apa dan kapan.
     *
     * @return array<int, array{type: string, text: string, at: string}>
     */
    private static function activities(): array
    {
        $recentBookings = Booking::with('item.category')
            ->latest('created_at')->limit(self::ACTIVITY_LIMIT)->get()
            ->map(fn ($b) => [
                'type' => 'booking_created',
                'text' => 'Booking baru — '.($b->item?->category?->name ?? 'Villa').' ('.$b->kode_booking.')',
                'at' => $b->created_at,
            ]);

        $completed = Booking::with('item.category')
            ->where('status', Booking::STATUS_SELESAI)
            ->latest('updated_at')->limit(self::ACTIVITY_LIMIT)->get()
            ->map(fn ($b) => [
                'type' => 'booking_completed',
                'text' => 'Booking selesai — '.($b->item?->category?->name ?? 'Villa').' ('.$b->kode_booking.')',
                'at' => $b->updated_at,
            ]);

        $surveys = Survey::with('category')
            ->latest('created_at')->limit(self::ACTIVITY_LIMIT)->get()
            ->map(fn ($s) => [
                'type' => 'survey_scheduled',
                'text' => 'Survey dijadwalkan — '.($s->category?->name ?? 'Villa').' ('.$s->guest_name.')',
                'at' => $s->created_at,
            ]);

        $payments = LedgerEntry::income()->whereNotNull('booking_id')->with('booking.item.category')
            ->latest('created_at')->limit(self::ACTIVITY_LIMIT)->get()
            ->map(fn ($e) => [
                'type' => 'payment_received',
                'text' => (str_contains($e->description, 'DP') ? 'Pembayaran DP diterima' : 'Pembayaran diterima')
                    .' — '.($e->booking?->item?->category?->name ?? 'Villa'),
                'at' => $e->created_at,
            ]);

        return $recentBookings->concat($completed)->concat($surveys)->concat($payments)
            ->sortByDesc('at')
            ->take(self::ACTIVITY_LIMIT)
            ->map(fn ($a) => ['type' => $a['type'], 'text' => $a['text'], 'at' => $a['at']->toIso8601String()])
            ->values()
            ->all();
    }
}
