<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Janji temu survey lokasi (A5 customer & B4 admin).
 *
 * Baris hanya ada untuk slot yang DIPESAN — slot kosong tidak ditabelkan,
 * melainkan dibangkitkan dari config/survey.php. Lihat `SurveySlots`.
 */
class Survey extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_TERJADWAL = 'Terjadwal';

    public const STATUS_MENUNGGU_LAPORAN = 'Menunggu Laporan';

    public const STATUS_SELESAI = 'Selesai';

    public const STATUS_DIBATALKAN = 'Dibatalkan';

    /** Label persis seperti badge di SurveyManagement.tsx. */
    public const STATUSES = [
        self::STATUS_TERJADWAL,
        self::STATUS_MENUNGGU_LAPORAN,
        self::STATUS_SELESAI,
        self::STATUS_DIBATALKAN,
    ];

    /**
     * Status yang MEMAKAI kuota sebuah sesi. Survey yang dibatalkan
     * mengembalikan slotnya, sejalan dengan cara booking `Dibatalkan`
     * melepaskan tanggalnya.
     */
    public const OCCUPYING_STATUSES = [
        self::STATUS_TERJADWAL,
        self::STATUS_MENUNGGU_LAPORAN,
        self::STATUS_SELESAI,
    ];

    protected $fillable = [
        'category_id',
        'item_id',
        'booking_id',
        'guest_id',
        'guest_name',
        'planned_check_in',
        'scheduled_date',
        'scheduled_time',
        'session',
        'pic_user_id',
        'status',
        'report',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'planned_check_in' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }
}
