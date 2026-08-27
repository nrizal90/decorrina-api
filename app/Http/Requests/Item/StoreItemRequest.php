<?php

namespace App\Http\Requests\Item;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah item (B4 — ItemForm, tombol "Simpan Draft" / "Simpan & Aktifkan").
 * Otorisasi ditangani middleware `rbac` (items:store).
 */
class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity_type' => ['sometimes', Rule::in(['Tetap', 'Rentang'])],
            'cap_min' => ['required', 'integer', 'min:0'],
            'cap_max' => ['required', 'integer', 'min:0', 'gte:cap_min'],
            'price_weekday' => ['required', 'integer', 'min:0'],
            'price_weekend' => ['required', 'integer', 'min:0'],
            'payment_mode' => ['required', Rule::in(Item::PAYMENT_MODES)],
            // Wajib bila metode pembayaran melibatkan DP — cocok dengan
            // `showDpMinimum` di ItemForm.tsx.
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
