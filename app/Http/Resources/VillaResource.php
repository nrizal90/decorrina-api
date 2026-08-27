<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kartu villa di katalog publik A2 (VillaListing.tsx).
 *
 * Harga & kapasitas TIDAK disimpan di kategori — keduanya diturunkan dari item
 * aktif di dalamnya (agregat withMin/withMax dari controller):
 *  - `price_from`      → "Mulai dari Rp 4.000.000 /malam"
 *  - `cap_min/cap_max` → "8–30 orang"
 *
 * `coming_soon` menggantikan harga dengan label "Segera hadir" di kartu, sesuai
 * perlakuan Wisata Camping di frontend: kategori nonaktif (atau belum punya
 * item aktif) tetap tampil, hanya diredupkan dan tombolnya dimatikan.
 *
 * @mixin Category
 */
class VillaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $comingSoon = $this->status !== 'Aktif' || $this->price_from === null;

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'tagline' => $this->tagline,
            'coming_soon' => $comingSoon,
            'price_from' => $comingSoon ? null : (int) $this->price_from,
            'capacity' => [
                'min' => $this->cap_min === null ? null : (int) $this->cap_min,
                'max' => $this->cap_max === null ? null : (int) $this->cap_max,
            ],
            'photo' => null, // menyusul bersama endpoint upload foto
        ];
    }
}
