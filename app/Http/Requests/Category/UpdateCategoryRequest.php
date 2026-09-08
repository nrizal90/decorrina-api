<?php

namespace App\Http\Requests\Category;

use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah kategori (B4). Semua field opsional — dukung PATCH parsial.
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
            'facility_ids' => ['sometimes', 'array'],
            'facility_ids.*' => ['integer', new ExistsInTenant('facilities')],
        ];
    }
}
