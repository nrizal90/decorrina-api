<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah kategori (B4 — modal "Tambah Kategori").
 * Otorisasi ditangani middleware `rbac` (categories:store).
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Boleh dikosongkan — controller menurunkannya dari `name`.
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
            'facility_ids' => ['sometimes', 'array'],
            'facility_ids.*' => ['integer', 'exists:facilities,id'],
        ];
    }
}
