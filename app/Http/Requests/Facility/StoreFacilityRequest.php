<?php

namespace App\Http\Requests\Facility;

use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah fasilitas (B4 — modal "Tambah Fasilitas").
 */
class StoreFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', new ExistsInTenant('categories')],
        ];
    }
}
