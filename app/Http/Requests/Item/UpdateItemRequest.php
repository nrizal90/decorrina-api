<?php

namespace App\Http\Requests\Item;

use App\Models\Item;
use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah item (B4). Semua field opsional — dukung PATCH parsial.
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', new ExistsInTenant('categories')],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity_type' => ['sometimes', Rule::in(['Tetap', 'Rentang'])],
            // Dikirim berpasangan supaya perbandingan gte selalu punya pembanding.
            'cap_min' => ['sometimes', 'required_with:cap_max', 'integer', 'min:0'],
            'cap_max' => ['sometimes', 'required_with:cap_min', 'integer', 'min:0', 'gte:cap_min'],
            'price_weekday' => ['sometimes', 'integer', 'min:0'],
            'price_weekend' => ['sometimes', 'integer', 'min:0'],
            'payment_mode' => ['sometimes', Rule::in(Item::PAYMENT_MODES)],
            'dp_minimum' => [
                'nullable',
                'integer',
                'min:0',
                Rule::requiredIf(fn () => in_array(
                    $this->input('payment_mode'),
                    ['DP + Pelunasan', 'Keduanya — customer memilih'],
                    true,
                )),
            ],
            'requires_survey' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
        ];
    }

    public function messages(): array
    {
        return [
            'cap_max.gte' => 'Kapasitas maksimum tidak boleh lebih kecil dari kapasitas minimum.',
            'dp_minimum.required' => 'Nominal DP minimum wajib diisi untuk metode pembayaran yang memakai DP.',
        ];
    }
}
