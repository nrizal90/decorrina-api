<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item = kamar/paket di dalam sebuah kategori (villa). Sumber kebenaran harga
 * & kapasitas yang dipakai booking (Fase 3).
 */
class Item extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Nilai `payment_mode` — string persis seperti radio di ItemForm.tsx. */
    public const PAYMENT_MODES = [
        'Full Payment',
        'DP + Pelunasan',
        'Keduanya — customer memilih',
    ];

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'capacity_type',
        'cap_min',
        'cap_max',
        'price_weekday',
        'price_weekend',
        'payment_mode',
        'dp_minimum',
        'requires_survey',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'cap_min' => 'integer',
            'cap_max' => 'integer',
            'price_weekday' => 'integer',
            'price_weekend' => 'integer',
            'dp_minimum' => 'integer',
            'requires_survey' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ItemPhoto::class)->orderBy('sort_order');
    }

    /**
     * Booking yang menempati item ini. Dipakai katalog publik untuk menyaring
     * villa berdasarkan tanggal — dipasangkan dengan scope `blocking()` dan
     * `overlapping()` di Booking, jangan menulis ulang aturan bentroknya.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function addons(): MorphToMany
    {
        return $this->morphToMany(Addon::class, 'linkable', 'addon_links');
    }
}
