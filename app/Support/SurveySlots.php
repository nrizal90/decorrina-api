<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * Aturan jadwal survey lokasi (CR-07, klarifikasi klien 27 Sep 2026).
 *
 * Tidak ada slot baku: tamu memilih tanggal, lalu jam mulai & jam selesai.
 * Satu implementasi dipakai layar A5 (menampilkan jendela & jam terisi),
 * booking customer/admin, dan papan survey admin B4.
 *
 *  - Jendela jam per VILLA per tanggal: 07:00–20:00 bila villa kosong,
 *    12:00–14:00 bila ada tamu. Hari check-in & check-out tamu dihitung
 *    "ada tamu".
 *  - Satu villa satu survey pada satu waktu: jam tidak boleh bertumpuk
 *    dengan survey lain di villa yang sama.
 *  - Paling lambat H-1 sebelum check-in (bila check-in diketahui).
 *  - Jalur customer: paling cepat H+2 dari hari ini.
 *
 * Jam dibandingkan sebagai string "HH:MM" — aman karena selalu 2 digit.
 */
class SurveySlots
{
    /** Tanggal terakhir yang masih boleh dipakai survey untuk sebuah check-in. */
    public static function deadlineFor(string $checkIn): Carbon
    {
        return Carbon::parse($checkIn)->startOfDay()->subDays((int) config('survey.deadline_days'));
    }

    /** Tanggal paling awal untuk jalur customer. */
    public static function earliest(): Carbon
    {
        return Carbon::today()->addDays((int) config('survey.lead_days'));
    }

    /** Ada booking aktif di villa ini yang menempati tanggal itu (check-in s/d check-out, inklusif)? */
    public static function villaOccupied(int $categoryId, string $date): bool
    {
        return Booking::query()
            ->blocking()
            ->whereHas('item', fn ($q) => $q->where('category_id', $categoryId))
            ->whereDate('check_in', '<=', $date)
            ->whereDate('check_out', '>=', $date)
            ->exists();
    }

    /** @return array{start: string, end: string} */
    public static function windowFor(int $categoryId, string $date): array
    {
        return self::villaOccupied($categoryId, $date)
            ? config('survey.window_occupied')
            : config('survey.window_empty');
    }

    /**
     * Rentang jam yang sudah terpakai survey lain di villa ini pada tanggal itu.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public static function takenFor(int $categoryId, string $date, ?int $exceptSurveyId = null): array
    {
        return Survey::query()
            ->where('category_id', $categoryId)
            ->whereDate('scheduled_date', $date)
            ->whereIn('status', Survey::OCCUPYING_STATUSES)
            ->when($exceptSurveyId, fn ($q) => $q->whereKeyNot($exceptSurveyId))
            ->orderBy('scheduled_time')
            ->get(['scheduled_time', 'scheduled_end_time'])
            ->map(fn ($s) => [
                'start' => substr((string) $s->scheduled_time, 0, 5),
                'end' => substr((string) $s->scheduled_end_time, 0, 5),
            ])
            ->all();
    }

    /**
     * Pesan error bila jadwal tidak sah, null bila boleh.
     *
     * Dipanggil ulang saat booking/survey benar-benar disimpan — jam yang
     * dilihat tamu di layar bisa sudah diambil orang lain beberapa menit
     * kemudian.
     */
    public static function rejectionFor(
        int $categoryId,
        string $date,
        string $start,
        string $end,
        ?string $checkIn,
        bool $enforceLeadTime = true,
        ?int $exceptSurveyId = null,
    ): ?string {
        $day = Carbon::parse($date)->startOfDay();

        if ($end <= $start) {
            return 'Jam selesai survey harus setelah jam mulai.';
        }

        if ($enforceLeadTime && $day->lessThan(self::earliest())) {
            return 'Survey paling cepat '.self::earliest()->toDateString().'.';
        }

        if ($checkIn !== null && $day->greaterThan(self::deadlineFor($checkIn))) {
            return 'Survey harus dijadwalkan paling lambat '.self::deadlineFor($checkIn)->toDateString().' (H-1 sebelum check-in).';
        }

        $window = self::windowFor($categoryId, $date);

        if ($start < $window['start'] || $end > $window['end']) {
            $reason = self::villaOccupied($categoryId, $date) ? 'villa ada tamu pada tanggal itu' : 'villa kosong pada tanggal itu';

            return "Survey hanya bisa pukul {$window['start']}–{$window['end']} ({$reason}).";
        }

        // ponytail: cek-lalu-simpan tanpa lock; dua pemesan di detik yang sama bisa lolos berdua. Tambah lock per villa bila itu terjadi.
        foreach (self::takenFor($categoryId, $date, $exceptSurveyId) as $taken) {
            if ($taken['start'] < $end && $taken['end'] > $start) {
                return "Jam itu bentrok dengan survey lain pukul {$taken['start']}–{$taken['end']}.";
            }
        }

        return null;
    }
}
