<?php

namespace App\Http\Requests\Villa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Slot survey ditanyakan relatif terhadap TANGGAL CHECK-IN, karena batasnya
 * H-7 sebelum menginap - bukan rentang bebas yang ditentukan frontend.
 */
class SurveySlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'item_id' => ['nullable', 'integer'],
        ];
    }
}
