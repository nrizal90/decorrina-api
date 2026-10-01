<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token Sanctum yang pemiliknya dicari TANPA scope tenant.
 *
 * User memakai BelongsToTenant. Di rute publik, `tenant.public` sudah
 * mengikat tenant sebelum controller memanggil auth('sanctum')->user(), jadi
 * pencarian pemilik token ikut tersaring `users.tenant_id = <tenant>` — akun
 * customer (tenant_id NULL) tidak ketemu dan booking-nya tercatat anonim.
 * Token adalah identitas global; tenant baru berlaku setelah user dikenali.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope('tenant');
    }
}
