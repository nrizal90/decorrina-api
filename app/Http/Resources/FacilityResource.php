<?php

namespace App\Http\Resources;

use App\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fasilitas (B4 & chip fasilitas di A3).
 *
 * @mixin Facility
 */
class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'status' => $this->status,
            'categories_count' => $this->whenCounted('categories'),
        ];
    }
}
