<?php

namespace App\Http\Requests\Addon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah add-on (B4 — modal "Tambah Add-on").
 *
 * `links` di form bisa menunjuk kategori maupun item, jadi dikirim sebagai dua
 * daftar id terpisah lalu digabung ke pivot polimorfik `addon_links`.
 */
class StoreAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            // Belum ada input di AddonMaster.tsx; dipakai A7 untuk label satuan.
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
