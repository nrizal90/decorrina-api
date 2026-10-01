<?php

namespace App\Http\Resources;

use App\Models\Reschedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reschedule
 */
class RescheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'kode_booking' => $this->whenLoaded('booking', fn () => $this->booking?->kode_booking),
            'guest_name' => $this->whenLoaded('booking', fn () => $this->booking?->guest?->name),
            'source' => $this->source,
            'status' => $this->status,
            'old_item' => $this->oldItem?->name,
            'old_check_in' => $this->old_check_in->toDateString(),
            'old_check_out' => $this->old_check_out->toDateString(),
            'old_total' => $this->old_total,
            'new_item' => $this->newItem?->name,
            'new_item_id' => $this->new_item_id,
            'new_check_in' => $this->new_check_in->toDateString(),
            'new_check_out' => $this->new_check_out->toDateString(),
            'new_total' => $this->new_total,
            'difference' => $this->difference,
            // Tagihan selisih yang belum dibayar (hanya booking Lunas).
            'outstanding' => $this->outstandingDifference(),
            'difference_paid_at' => $this->difference_paid_at,
            'reason' => $this->reason,
            'admin_note' => $this->admin_note,
            'created_at' => $this->created_at,
            'decided_at' => $this->decided_at,
        ];
    }
}
