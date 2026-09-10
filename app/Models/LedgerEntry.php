<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu baris buku kas. Lihat migrasi create_ledger_entries_table untuk dua
 * sumbernya (otomatis dari booking vs manual).
 */
class LedgerEntry extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPE_PEMASUKAN = 'Pemasukan';

    public const TYPE_PENGELUARAN = 'Pengeluaran';

    public const TYPES = [self::TYPE_PEMASUKAN, self::TYPE_PENGELUARAN];

    protected $fillable = [
        'entry_date',
        'description',
        'type',
        'category',
        'amount',
        'booking_id',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PEMASUKAN);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PENGELUARAN);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }
}
