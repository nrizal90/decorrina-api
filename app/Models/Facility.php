<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fasilitas kawasan/villa (B4). Ditampilkan sebagai chip di A3.
 */
class Facility extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'name',
        'icon',
        'status',
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }
}
