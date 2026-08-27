<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto item. Tabelnya sudah ada agar ItemResource bisa mengembalikan `photos`
 * sejak sekarang; endpoint upload-nya belum dikerjakan (lihat roadmap Fase 2).
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
