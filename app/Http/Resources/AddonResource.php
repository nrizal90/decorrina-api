<?php

namespace App\Http\Resources;

use App\Models\Addon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Add-on (B4 & kartu add-on di A7).
 *
 * `links` adalah bentuk datar dari pivot polimorfik — persis yang dibutuhkan
 * kolom "Terhubung ke Item/Kategori" di AddonMaster.tsx, yang hanya menampilkan
 * label. `type` disertakan agar FE bisa membedakan saat mengedit.
 *
 * @mixin Addon
 */
class AddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'description' => $this->description,
            'unit' => $this->unit,
            'deadline_days' => $this->deadline_days,
            'status' => $this->status,
            'links' => $this->when(
                $this->relationLoaded('categories') && $this->relationLoaded('items'),
                fn () => $this->categories
                    ->map(fn ($c) => ['type' => 'category', 'id' => $c->id, 'label' => $c->name])
                    ->concat($this->items->map(fn ($i) => ['type' => 'item', 'id' => $i->id, 'label' => $i->name]))
                    ->values(),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
