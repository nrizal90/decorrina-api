<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tamu yang menginap. Dibuat otomatis dari data booking; layar CRM penuh
 * menyusul di Fase 7.
 */
class Guest extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Nilai `guest_type` — label persis seperti di form A6. */
    public const TYPES = ['Pribadi', 'Instansi'];

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'birth_date',
        'origin',
        'guest_type',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Booking paling baru — untuk angka "jumlah tamu / kendaraan" di profil,
     * yang memang milik satu kali kunjungan, bukan sifat tetap tamunya.
     */
    public function latestBooking(): HasOne
    {
        return $this->hasOne(Booking::class)->latestOfMany('check_in');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
