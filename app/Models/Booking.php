<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booking — inti Fase 3. Menunjuk ke ITEM (kamar/paket), bukan kategori.
 */
class Booking extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_MENUNGGU = 'Menunggu Pembayaran';

    public const STATUS_DP = 'DP Dibayar';

    public const STATUS_LUNAS = 'Lunas';

    public const STATUS_SELESAI = 'Selesai';

    public const STATUS_DIBATALKAN = 'Dibatalkan';

    /** Semua status yang dikenal — sama persis dengan union type di FE. */
    public const STATUSES = [
        self::STATUS_MENUNGGU,
        self::STATUS_DP,
        self::STATUS_LUNAS,
        self::STATUS_SELESAI,
        self::STATUS_DIBATALKAN,
    ];

    /**
     * State machine: status mana yang boleh dituju dari status sekarang.
     *
     * Alurnya maju satu arah — pembayaran tidak bisa "dibatalkan sebagian".
     * `Selesai` dan `Dibatalkan` bersifat final: koreksi setelahnya adalah
     * urusan pembukuan (Fase 7), bukan mengubah status kembali.
     */
    public const TRANSITIONS = [
        self::STATUS_MENUNGGU => [self::STATUS_DP, self::STATUS_LUNAS, self::STATUS_DIBATALKAN],
        self::STATUS_DP => [self::STATUS_LUNAS, self::STATUS_DIBATALKAN],
        self::STATUS_LUNAS => [self::STATUS_SELESAI, self::STATUS_DIBATALKAN],
        self::STATUS_SELESAI => [],
        self::STATUS_DIBATALKAN => [],
    ];

    /** Status benefit (B6) — diturunkan dari `benefit_shown_at`. */
    public const BENEFIT_BELUM = 'Belum Digunakan';

    public const BENEFIT_SUDAH = 'Sudah Ditunjukkan';

    /** Status yang membuat tamu berhak atas kode akses kawasan. */
    public const PAID_STATUSES = [self::STATUS_DP, self::STATUS_LUNAS];

    /** Prefix kode akses: Gerbang Gunungsari. */
    public const ACCESS_CODE_PREFIX = 'GNG-';

    /**
     * Huruf/angka yang dipakai kode akses. Tanpa 0/O dan 1/I/L: kode dibaca
     * mata security dari layar HP tamu, bukan dipindai.
     */
    private const ACCESS_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Status yang TIDAK memblokir tanggal — kamar kembali tersedia. */
    public const RELEASING_STATUSES = [self::STATUS_DIBATALKAN];

    protected $fillable = [
        'item_id',
        'guest_id',
        'user_id',
        'kode_booking',
        'kode_akses_kawasan',
        'benefit_shown_at',
        'check_in',
        'check_out',
        'nights',
        'pax',
        'vehicle_count',
        'price_weekday',
        'price_weekend',
        'price_holiday',
        'subtotal_item',
        'subtotal_addons',
        'total',
        'payment_mode',
        'dp_minimum',
        'status',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'nights' => 'integer',
            'pax' => 'integer',
            'vehicle_count' => 'integer',
            'price_weekday' => 'integer',
            'price_weekend' => 'integer',
            'price_holiday' => 'integer',
            'subtotal_item' => 'integer',
            'subtotal_addons' => 'integer',
            'total' => 'integer',
            'dp_minimum' => 'integer',
            'benefit_shown_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }

    /** Booking yang masih memblokir tanggal (semua kecuali yang dibatalkan). */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::RELEASING_STATUSES);
    }

    /**
     * Booking yang tanggalnya bertabrakan dengan rentang yang diminta.
     *
     * Check-out hari X dan check-in hari X TIDAK dianggap bentrok — kamar
     * berganti tamu di hari yang sama, itu normal di perhotelan. Karena itu
     * perbandingannya `<` dan `>`, bukan `<=` / `>=`.
     */
    public function scopeOverlapping(Builder $query, string $checkIn, string $checkOut): Builder
    {
        return $query
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);
    }

    public function benefitStatus(): ?string
    {
        if ($this->kode_akses_kawasan === null) {
            return null;
        }

        return $this->benefit_shown_at === null ? self::BENEFIT_BELUM : self::BENEFIT_SUDAH;
    }

    /**
     * Beri kode akses kawasan bila belum punya. Idempoten: kode yang sudah
     * dipegang tamu tidak pernah diganti, berapa kali pun status berubah.
     */
    public function ensureAccessCode(): void
    {
        if ($this->kode_akses_kawasan !== null) {
            return;
        }

        do {
            $code = self::ACCESS_CODE_PREFIX;
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ACCESS_CODE_ALPHABET[random_int(0, strlen(self::ACCESS_CODE_ALPHABET) - 1)];
            }
        } while (static::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('kode_akses_kawasan', $code)
            ->exists());

        $this->update(['kode_akses_kawasan' => $code]);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
