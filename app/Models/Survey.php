<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Janji temu survey lokasi (A5).
 *
 * Baris hanya ada untuk slot yang DIPESAN — slot kosong tidak ditabelkan,
 * melainkan dibangkitkan dari config/survey.php. Lihat `SurveySlots`.
 */
class Survey extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_DIJADWALKAN = 'Dijadwalkan';

    public const STATUS_SELESAI = 'Selesai';

    public const STATUS_DIBATALKAN = 'Dibatalkan';

    /**
     * Status yang MEMAKAI kuota sebuah sesi. Survey yang dibatalkan
     * mengembalikan slotnya, sejalan dengan cara booking `Dibatalkan`
     * melepaskan tanggalnya.
     */
    public const OCCUPYING_STATUSES = [self::STATUS_DIJADWALKAN, self::STATUS_SELESAI];

    protected $fillable = [
        'item_id',
        'booking_id',
        'scheduled_date',
        'session',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
