<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kategori untuk layar admin B4 (CategoryList.tsx).
 *
 * `items_count` mengisi kolom "Jumlah Item" — controller wajib memanggil
 * withCount('items') agar tidak jadi query N+1.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'description' => $this->description,
            'tagline' => $this->tagline,
            'location' => $this->location,
            'status' => $this->status,
            'items_count' => $this->whenCounted('items'),
            'facilities' => FacilityResource::collection($this->whenLoaded('facilities')),
            'created_at' => $this->created_at,
        ];
    }
}
