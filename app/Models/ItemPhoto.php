<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto item. Diunggah lewat ItemPhotoController (B4).
 *
 * Tidak memakai BelongsToTenant — isolasi diturunkan dari `items`.
 */
class ItemPhoto extends Model
{
    protected $fillable = [
        'item_id',
        'path',
        'sort_order',
        'is_cover',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
        ];
    }

    /** URL publik dari disk `booking.photos_disk` — lokal sekarang, S3 nanti. */
    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk(config('booking.photos_disk'))->url($this->path));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
