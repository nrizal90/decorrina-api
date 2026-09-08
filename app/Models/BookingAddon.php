<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris add-on pada sebuah booking. `unit_price` & `subtotal` adalah snapshot —
 * mengubah harga add-on di master data tidak boleh mengubah booking lama.
 */
class BookingAddon extends Model
{
    protected $fillable = [
        'addon_id',
        'qty',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
