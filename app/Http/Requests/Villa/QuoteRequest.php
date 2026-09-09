<?php

namespace App\Http\Requests\Villa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Permintaan rincian harga untuk satu rencana menginap (A4).
 *
 * Ada supaya frontend tidak perlu menyalin aturan tarif. Perhitungannya tetap
 * `BookingPricing` yang sama dengan yang dipakai saat booking benar-benar
 * dibuat — termasuk definisi malam weekend, yang sempat berbeda di frontend.
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Kepemilikan item terhadap villa diperiksa di controller: pesannya
            // lebih jelas di sana ketimbang sebagai kegagalan aturan `exists`.
            'item_id' => ['required', 'integer'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ];
    }
}
