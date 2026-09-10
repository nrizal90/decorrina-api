<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengganti `exists:{tabel},id` untuk tabel BER-TENANT.
 *
 * Kenapa perlu: `exists` bawaan Laravel adalah query DB mentah — ia TIDAK
 * melewati Eloquent, jadi Global Scope `BelongsToTenant` tidak berlaku. Akibatnya
 * admin tenant A bisa mengirim id milik tenant B dan lolos validasi. Barisnya
 * lalu tercipta dengan `tenant_id` tenant A tapi menunjuk induk milik tenant B —
 * data rusak yang tidak menimbulkan gejala apa pun, karena saat DIBACA Global
 * Scope justru menyembunyikan induknya (relasi terbaca null).
 *
 * Aturan ini menutupnya dengan menambahkan dua syarat yang selalu terlupa:
 *  1. `tenant_id` harus sama dengan tenant aktif;
 *  2. baris yang sudah di-soft-delete tidak dianggap ada — hanya untuk tabel
 *     yang MEMANG punya `deleted_at`. `users` tidak memakai soft delete
 *     (dinonaktifkan lewat kolom `status`, lihat migrasi add_status_to_users),
 *     dan menambahkan syarat itu tanpa syarat akan meledak jadi 500.
 *
 * Fail-closed: tanpa konteks tenant, validasi GAGAL — bukan lolos. Pada praktiknya
 * konteks selalu ada untuk request tulis (SetTenantMiddleware mengikatnya dari
 * `users.tenant_id`, atau dari header `X-Tenant` untuk superadmin-azatech, dan
 * menolak 422 bila vendor menulis tanpa menyebut tenant).
 *
 * Pemakaian:
 *   'category_id' => ['required', 'integer', new ExistsInTenant('categories')],
 */
class ExistsInTenant implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app()->bound('currentTenantId')) {
            $fail('Konteks klien tidak diketahui, sehingga pilihan :attribute tidak dapat diverifikasi.');

            return;
        }

        $exists = DB::table($this->table)
            ->where($this->column, $value)
            ->where('tenant_id', app('currentTenantId'))
            ->when(
                Schema::hasColumn($this->table, 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at'),
            )
            ->exists();

        if (! $exists) {
            $fail('Pilihan :attribute tidak ditemukan pada data klien ini.');
        }
    }
}
