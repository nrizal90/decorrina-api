<?php

namespace App\Http\Requests\Booking;

use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Booking manual oleh admin (B3). Otorisasi ditangani middleware `rbac`
 * (bookings:store).
 *
 * Metode pembayaran TIDAK diminta di sini: keputusan 2026-09-08 menetapkan
 * mode mengikuti pengaturan item, jadi controller menyalinnya dari item.
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', new ExistsInTenant('items')],

            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],

            'check_in' => ['required', 'date_format:Y-m-d'],
            // after: menginap minimal satu malam — check-out tak boleh sama
            // dengan check-in.
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],

            'pax' => ['required', 'integer', 'min:1'],

            'addons' => ['sometimes', 'array'],
            'addons.*.addon_id' => ['required', 'integer', new ExistsInTenant('addons')],
            'addons.*.qty' => ['sometimes', 'integer', 'min:1', 'max:99'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.after' => 'Tanggal check-out harus setelah tanggal check-in (menginap minimal satu malam).',
            'pax.min' => 'Jumlah tamu minimal 1 orang.',
        ];
    }
}
