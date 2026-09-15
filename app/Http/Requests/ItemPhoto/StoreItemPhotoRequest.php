<?php

namespace App\Http\Requests\ItemPhoto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload foto item (B4 tab General). Satu file per request — FE mengirim
 * berurutan agar progress per foto bisa ditampilkan.
 */
class StoreItemPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('booking.photo_max_kb')],
        ];
    }
}
