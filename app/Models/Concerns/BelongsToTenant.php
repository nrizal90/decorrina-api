<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dipakai model domain (Booking, Item, Guest, Ledger, dst) mulai Fase 2+.
 *
 * Dua fungsi:
 *  1. Global Scope — memfilter query ke tenant aktif HANYA saat `currentTenantId`
 *     terikat di container (yaitu request user internal, di-set oleh
 *     SetTenantMiddleware). Untuk customer/azatech scope tidak terpasang —
 *     mereka mengandalkan ownership (customer) atau Gate bypass (azatech).
 *  2. Auto-fill `tenant_id` saat membuat record baru, dari tenant aktif.
 *
 * Lihat docs/06-rbac-decorina.md §3.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->bound('currentTenantId')) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', app('currentTenantId'));
            }
        });

        static::creating(function ($model) {
            if ($model->tenant_id === null && app()->bound('currentTenantId')) {
                $model->tenant_id = app('currentTenantId');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
