<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant = satu bisnis klien yang di-host Azatech.
 * Inti multi-tenancy — lihat docs/06-rbac-decorina.md.
 */
class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'status',
    ];

    /**
     * User internal (staff/admin/superadmin-klien/stakeholder) milik tenant ini.
     * Customer & superadmin-azatech TIDAK terikat tenant (tenant_id NULL).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
