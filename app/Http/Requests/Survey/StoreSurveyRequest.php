<?php

namespace App\Http\Requests\Survey;

use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Jadwalkan survey dari sisi admin (modal "Jadwalkan Survey", B4).
 *
 * Berbeda dari jalur customer: admin menjadwalkan untuk calon tamu yang baru
 * menghubungi lewat WhatsApp, jadi belum tentu ada booking, item, atau tamu
 * terdaftar — dan jamnya bebas, tidak harus jatuh di sesi baku.
 */
class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', new ExistsInTenant('categories')],
            'item_id' => ['nullable', 'integer', new ExistsInTenant('items')],

            // Satu kolom di layar: "Nama tamu atau nomor WhatsApp".
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_id' => ['nullable', 'integer', new ExistsInTenant('guests')],

            // Dasar aturan H-7 ketika belum ada booking. Opsional karena calon
            // tamu kadang menyurvei dulu sebelum punya tanggal pasti.
            'planned_check_in' => ['nullable', 'date_format:Y-m-d'],

            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],

            'pic_user_id' => ['nullable', 'integer', new ExistsInTenant('users')],
            'notes' => ['nullable', 'string'],
        ];
    }
}
