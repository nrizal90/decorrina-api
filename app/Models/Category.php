<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kategori = "villa" yang tampil di katalog publik (A2/A3), sekaligus induk
 * item di admin (B4). Slug dipakai /api/villas/{slug}.
 */
class Category extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'description',
        'tagline',
        'location',
        'status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class);
    }

    public function addons(): MorphToMany
    {
        return $this->morphToMany(Addon::class, 'linkable', 'addon_links');
    }

    /**
     * Item yang benar-benar bisa dipesan — dipakai untuk menghitung harga
     * "Mulai dari" dan rentang kapasitas di kartu A2.
     */
    public function activeItems(): HasMany
    {
        return $this->items()->where('status', 'Aktif');
    }
}
