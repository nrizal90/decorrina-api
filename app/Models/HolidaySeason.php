<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Periode libur (B4 — Master Holiday Season). Malam di dalam rentang
 * `start_date`..`end_date` (inklusif) ditagih tarif holiday item.
 */
class HolidaySeason extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }
}
