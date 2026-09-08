<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tamu yang menginap. Dibuat otomatis dari data booking; layar CRM penuh
 * menyusul di Fase 7.
 */
class Guest extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
