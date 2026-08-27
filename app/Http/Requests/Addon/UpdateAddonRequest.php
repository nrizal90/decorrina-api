<?php

namespace App\Http\Requests\Addon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah add-on (B4). Semua field opsional — dukung PATCH parsial.
 */
class UpdateAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'max:30'],
            'deadline_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'item_ids' => ['sometimes', 'array'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ];
    }
}
