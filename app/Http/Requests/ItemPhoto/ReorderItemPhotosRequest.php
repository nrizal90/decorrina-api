<?php

namespace App\Http\Requests\ItemPhoto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Urutan + cover foto item. FE mengirim SELURUH daftar id milik item itu
 * dalam urutan baru; id yang bukan milik item ditolak di controller.
 */
class ReorderItemPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['integer', 'distinct'],
            'cover_id' => ['nullable', 'integer'],
        ];
    }
}
