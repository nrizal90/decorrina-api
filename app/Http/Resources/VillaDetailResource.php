<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Halaman detail villa publik A3 (VillaDetail.tsx).
 *
 * Menyertakan `items` (kartu tarif weekday/weekend + bahan booking Fase 3),
 * `facilities` (chip fasilitas), dan `addons` — add-on ikut di sini supaya A7
 * tidak perlu endpoint tersendiri.
 *
 * @mixin Category
 */
class VillaDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $comingSoon = $this->status !== 'Aktif' || $this->price_from === null;

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'location' => $this->location,
            'coming_soon' => $comingSoon,
            'price_from' => $comingSoon ? null : (int) $this->price_from,
            'capacity' => [
                'min' => $this->cap_min === null ? null : (int) $this->cap_min,
                'max' => $this->cap_max === null ? null : (int) $this->cap_max,
            ],
            'facilities' => FacilityResource::collection($this->whenLoaded('facilities')),
            'items' => ItemResource::collection($this->whenLoaded('activeItems')),
            'addons' => AddonResource::collection($this->whenLoaded('addons')),
            'photos' => [], // menyusul bersama endpoint upload foto
        ];
    }
}
