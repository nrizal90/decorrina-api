<?php

namespace App\Http\Resources;

use App\Models\ItemPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Foto item. `url` diturunkan dari disk publik agar FE tidak perlu tahu
 * struktur penyimpanan.
 *
 * @mixin ItemPhoto
 */
class ItemPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => Storage::disk('public')->url($this->path),
            'sort_order' => $this->sort_order,
            'is_cover' => $this->is_cover,
        ];
    }
}
