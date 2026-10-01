<?php

namespace App\Http\Requests\HolidaySeason;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tambah/ubah holiday season. Store dan update memakai aturan yang sama —
 * modalnya selalu mengirim ketiga field.
 */
class HolidaySeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            // Libur satu hari: end_date = start_date.
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }
}
