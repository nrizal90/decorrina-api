<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah status booking (B3). Perpindahan yang sah divalidasi di controller
 * lewat state machine `Booking::TRANSITIONS` — di sini hanya dipastikan
 * statusnya dikenal.
 */
class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Booking::STATUSES)],
        ];
    }
}
