<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Booking untuk papan admin B3 dan detailnya.
 *
 * `villa` diratakan dari item->category supaya tabel tidak perlu menelusuri
 * relasi bertingkat — kolom "VILLA" di B3 hanya butuh namanya.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_booking' => $this->kode_booking,

            'guest' => [
                'id' => $this->guest?->id,
                'name' => $this->guest?->name,
                'phone' => $this->guest?->phone,
                'email' => $this->guest?->email,
            ],

            'item' => [
                'id' => $this->item?->id,
                'name' => $this->item?->name,
            ],
            'villa' => $this->item?->category?->name,

            'check_in' => $this->check_in?->toDateString(),
            'check_out' => $this->check_out?->toDateString(),
            'nights' => $this->nights,
            'pax' => $this->pax,

            'subtotal_item' => $this->subtotal_item,
            'subtotal_addons' => $this->subtotal_addons,
            'total' => $this->total,

            'payment_mode' => $this->payment_mode,
            'dp_minimum' => $this->dp_minimum,

            'status' => $this->status,
            // Dipakai FE untuk menyusun pilihan ubah status — daftar tujuan
            // yang sah datang dari backend, bukan disalin ulang di FE.
            'allowed_transitions' => Booking::TRANSITIONS[$this->status] ?? [],
            'source' => $this->source,
            'notes' => $this->notes,

            'addons' => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($line) => [
                'addon_id' => $line->addon_id,
                'name' => $line->addon?->name,
                'qty' => $line->qty,
                'unit_price' => $line->unit_price,
                'subtotal' => $line->subtotal,
            ])->values()),

            'created_at' => $this->created_at,
        ];
    }
}
