<?php

namespace App\Http\Requests\Booking;

use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;

/** Cek ketersediaan tanggal sebelum membuat booking (A4 & modal B3). */
class CheckAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', new ExistsInTenant('items')],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ];
    }
}
