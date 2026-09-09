<?php

namespace App\Support;

use App\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * Slot survey yang bisa dipilih pengunjung (A5).
 *
 * Slot dibangkitkan, tidak disimpan: untuk setiap tanggal yang masih memenuhi
 * syarat, tiap sesi di config/survey.php ditawarkan, lalu yang kuotanya sudah
 * habis dibuang. Menyimpan slot kosong berarti seseorang harus membuat dan
 * merawat ribuan baris yang tidak pernah dipakai.
 *
 * Tiga aturan tanggal, semuanya dari config:
 *  - `lead_days`     : paling cepat H+2 dari hari ini (tim butuh persiapan).
 *  - `deadline_days` : paling lambat H-7 sebelum check-in.
 *  - `max_slots_offered` : daftar dipotong supaya tetap bisa dipilih manusia.
 *
 * Angkanya masih DEFAULT UI dan belum dikonfirmasi klien (docs/05) — karena
 * itu tinggal di config, bukan tersebar sebagai angka di dalam kode.
 */
class SurveySlots
{
    /**
     * Tanggal terakhir yang masih boleh dipakai survey untuk sebuah check-in.
     */
    public static function deadlineFor(string $checkIn): Carbon
    {
        return Carbon::parse($checkIn)->startOfDay()->subDays((int) config('survey.deadline_days'));
    }

    /**
     * Tanggal paling awal yang boleh ditawarkan.
     */
    public static function earliest(): Carbon
    {
        return Carbon::today()->addDays((int) config('survey.lead_days'));
    }

    /**
     * Apakah satu slot masih sah DAN masih punya kuota?
     *
     * Dipakai ulang saat booking benar-benar dibuat: daftar slot yang dilihat
     * pengunjung bisa sudah basi beberapa menit kemudian, jadi pilihannya
     * diperiksa lagi alih-alih dipercaya begitu saja.
     */
    public static function isBookable(string $date, string $session, string $checkIn): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        if (! self::isKnownSession($session)) {
            return false;
        }

        if ($day->lessThan(self::earliest()) || $day->greaterThan(self::deadlineFor($checkIn))) {
            return false;
        }

        return self::remainingCapacity($date, $session) > 0;
    }

    /**
     * Slot yang ditawarkan ke layar A5 untuk sebuah tanggal check-in.
     *
     * @return array<int, array{date: string, session: string, start: string, end: string}>
     */
    public static function availableFor(string $checkIn): array
    {
        $deadline = self::deadlineFor($checkIn);
        $cursor = self::earliest();
        $max = (int) config('survey.max_slots_offered');

        // Check-in yang terlalu dekat: deadline sudah lewat, tidak ada slot.
        // Bukan kesalahan — layar A5 yang menjelaskannya ke pengunjung.
        $slots = [];

        while ($cursor->lessThanOrEqualTo($deadline) && count($slots) < $max) {
            foreach (config('survey.sessions') as $session) {
                if (count($slots) >= $max) {
                    break;
                }

                if (self::remainingCapacity($cursor->toDateString(), $session['code']) > 0) {
                    $slots[] = [
                        'date' => $cursor->toDateString(),
                        'session' => $session['code'],
                        'start' => $session['start'],
                        'end' => $session['end'],
                    ];
                }
            }

            $cursor->addDay();
        }

        return $slots;
    }

    private static function isKnownSession(string $session): bool
    {
        foreach (config('survey.sessions') as $known) {
            if ($known['code'] === $session) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sisa kuota sebuah sesi. Survey `Dibatalkan` mengembalikan slotnya,
     * sejalan dengan cara booking yang dibatalkan melepaskan tanggalnya.
     */
    private static function remainingCapacity(string $date, string $session): int
    {
        $taken = Survey::query()
            ->whereDate('scheduled_date', $date)
            ->where('session', $session)
            ->whereIn('status', Survey::OCCUPYING_STATUSES)
            ->count();

        return (int) config('survey.capacity_per_session') - $taken;
    }
}
