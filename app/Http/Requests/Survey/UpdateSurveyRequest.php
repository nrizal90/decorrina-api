<?php

namespace App\Http\Requests\Survey;

use App\Models\Survey;
use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah survey — dipakai dua hal di layar B4: menulis laporan hasil survey,
 * dan memindahkan jadwal / mengganti PIC.
 *
 * Semua opsional (`sometimes`): modal laporan hanya mengirim `report`, dan
 * tidak boleh ikut menghapus jadwal yang tidak disentuhnya.
 */
class UpdateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(Survey::STATUSES)],
            'report' => ['sometimes', 'nullable', 'string'],

            'scheduled_date' => ['sometimes', 'date_format:Y-m-d'],
            'scheduled_time' => ['sometimes', 'date_format:H:i'],
            'pic_user_id' => ['sometimes', 'nullable', 'integer', new ExistsInTenant('users')],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
