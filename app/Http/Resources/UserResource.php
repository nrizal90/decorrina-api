<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi user untuk API. Menyertakan `roles` & `permissions` agar FE
 * bisa guard tombol/menu BERDASAR PERMISSION (bukan role) — lihat dok 06 §9.
 *
 * Schema OpenAPI-nya ada di App\OpenApi\Schemas\User (schema: 'User') —
 * dipisah karena JsonResource memproksi properti lewat __get, jadi kelas ini
 * tidak boleh mendeklarasikan properti typed sebagai pembawa atribut.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'created_at' => $this->created_at,
        ];
    }
}
