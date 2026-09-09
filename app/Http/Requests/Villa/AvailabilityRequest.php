<?php

namespace App\Http\Requests\Villa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Rentang tanggal yang ditanyakan kalender A4.
 *
 * Dibiarkan berupa jendela (from/to), bukan satu bulan, supaya frontend bisa
 * mengambil sekali untuk beberapa bulan dan berpindah bulan tanpa memanggil
 * ulang — dan supaya rentang menginap yang melintasi bulan tetap terjawab.
 */
class AvailabilityRequest extends FormRequest
{
    /** Jendela default bila frontend tidak menyebutkan apa-apa. */
    private const DEFAULT_MONTHS = 6;

    /** Pagar atas: satu tahun sudah jauh melebihi kebutuhan layar kalender. */
    private const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after:from'],
        ];
    }

    public function from(): Carbon
    {
        return $this->filled('from')
            ? Carbon::parse($this->string('from')->toString())->startOfDay()
            : Carbon::today();
    }

    public function to(): Carbon
    {
        $to = $this->filled('to')
            ? Carbon::parse($this->string('to')->toString())->startOfDay()
            : $this->from()->copy()->addMonths(self::DEFAULT_MONTHS);

        // Dipangkas diam-diam, bukan ditolak: rentang berlebihan adalah salah
        // pakai, bukan kesalahan pengunjung — dan jawabannya tetap benar.
        $max = $this->from()->copy()->addDays(self::MAX_DAYS);

        return $to->greaterThan($max) ? $max : $to;
    }
}
