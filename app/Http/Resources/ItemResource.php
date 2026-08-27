<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item untuk layar admin B4 (ItemList.tsx & ItemForm.tsx).
 *
 * Nama field sengaja memakai snake_case penuh (`price_weekday`, bukan
 * `weekday` seperti di seed-data.ts) — hook FE yang menyesuaikan saat Fase 12,
 * bukan API yang mewarisi penamaan data demo.
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'description' => $this->description,
            'capacity_type' => $this->capacity_type,
            'cap_min' => $this->cap_min,
            'cap_max' => $this->cap_max,
            'price_weekday' => $this->price_weekday,
            'price_weekend' => $this->price_weekend,
            'payment_mode' => $this->payment_mode,
            'dp_minimum' => $this->dp_minimum,
            'requires_survey' => $this->requires_survey,
            'status' => $this->status,
            // Selalu ada agar FE tak perlu cek null; endpoint upload menyusul.
            'photos' => ItemPhotoResource::collection($this->whenLoaded('photos', fn () => $this->photos, collect())),
            'created_at' => $this->created_at,
        ];
    }
}
