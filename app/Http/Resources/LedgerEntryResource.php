<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tabel Ledger Harian (B9).
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'type' => $this->type,
            'category' => $this->category,
            'amount' => $this->amount,
            'booking_id' => $this->booking_id,
            'kode_booking' => $this->booking?->kode_booking,
            // Baris otomatis dari sistem tidak punya pencatat.
            'is_automatic' => $this->created_by === null && $this->booking_id !== null,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
