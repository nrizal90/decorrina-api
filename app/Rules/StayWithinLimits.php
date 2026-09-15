<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Batas durasi menginap & horizon check-out (audit 2026-09-11 T-04).
 *
 * Tanpa ini `check_out=9999-12-31` membuat BookingPricing mengiterasi jutaan
 * malam — 60 detik CPU per request anonim. Dipasang di SEMUA request yang
 * membawa check_out (publik & admin) supaya tidak ada jalur yang lolos.
 * Pasang setelah `date_format:Y-m-d` dan `after:check_in`.
 */
class StayWithinLimits implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $checkIn = $this->data['check_in'] ?? null;
        if (! is_string($checkIn) || ! is_string($value)) {
            return; // rule date_format/after sudah menolak duluan
        }

        try {
            $in = Carbon::createFromFormat('Y-m-d', $checkIn);
            $out = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return;
        }

        $maxNights = (int) config('booking.max_nights');
        if ($in->diffInDays($out) > $maxNights) {
            $fail("Durasi menginap maksimal {$maxNights} malam.");
        }

        $horizon = (int) config('booking.max_months_ahead');
        if ($out->gt(Carbon::today()->addMonths($horizon))) {
            $fail("Tanggal check-out maksimal {$horizon} bulan ke depan.");
        }
    }
}
