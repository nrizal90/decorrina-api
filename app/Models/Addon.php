<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Add-on opsional (B4, dipakai di A7). Bisa ditautkan ke kategori dan/atau
 * item lewat pivot polimorfik `addon_links`.
 */
class Addon extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'name',
        'price',
        'description',
        'unit',
        'deadline_days',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'deadline_days' => 'integer',
        ];
    }

    public function categories(): MorphToMany
    {
        return $this->morphedByMany(Category::class, 'linkable', 'addon_links');
    }

    public function items(): MorphToMany
    {
        return $this->morphedByMany(Item::class, 'linkable', 'addon_links');
    }
}
