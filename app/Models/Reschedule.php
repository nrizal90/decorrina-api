<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan / riwayat reschedule booking. Lihat migration & App\Support\Rescheduler.
 */
class Reschedule extends Model
{
    use BelongsToTenant;

    public const STATUS_MENUNGGU = 'Menunggu Persetujuan';

    public const STATUS_DISETUJUI = 'Disetujui';

    public const STATUS_DITOLAK = 'Ditolak';

    protected $fillable = [
        'booking_id',
        'source',
        'status',
        'old_item_id',
        'old_check_in',
        'old_check_out',
        'old_total',
        'new_item_id',
        'new_check_in',
        'new_check_out',
        'new_total',
        'difference',
        'difference_paid_at',
        'reason',
        'admin_note',
        'requested_by',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'old_check_in' => 'date',
            'old_check_out' => 'date',
            'new_check_in' => 'date',
            'new_check_out' => 'date',
            'old_total' => 'integer',
            'new_total' => 'integer',
            'difference' => 'integer',
            'difference_paid_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Selisih yang masih harus dibayar tamu: hanya booking yang sudah LUNAS.
     * Booking DP membayar total baru saat pelunasan (Ledger menghitung sisa
     * dari total), jadi selisihnya tidak perlu ditagih terpisah.
     */
    public function outstandingDifference(): int
    {
        if ($this->status !== self::STATUS_DISETUJUI || $this->difference <= 0 || $this->difference_paid_at !== null) {
            return 0;
        }

        return $this->booking?->status === Booking::STATUS_LUNAS ? $this->difference : 0;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withoutGlobalScope('tenant');
    }

    public function oldItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'old_item_id')->withoutGlobalScope('tenant');
    }

    public function newItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'new_item_id')->withoutGlobalScope('tenant');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withoutGlobalScope('tenant');
    }
}
