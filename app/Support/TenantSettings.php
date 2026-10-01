<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pengaturan yang bisa diubah admin per tenant (layar Settings).
 *
 * Nilai tersimpan di `tenant_settings`; key yang belum pernah disimpan jatuh
 * ke default di config/. Hanya key di DEFINITIONS yang bisa dibaca/ditulis —
 * menambah pengaturan baru = tambah satu baris di sini (+ default di config).
 */
class TenantSettings
{
    /** key => aturan validasi. Default diambil dari config dengan key yang sama. */
    public const DEFINITIONS = [
        'reschedule.quota' => ['required', 'integer', 'min:0', 'max:10'],
        'reschedule.deadline_days' => ['required', 'integer', 'min:0', 'max:90'],
        'reschedule.allow_change_nights' => ['required', 'boolean'],
        'reschedule.allow_change_item' => ['required', 'boolean'],
    ];

    public static function get(string $key, int $tenantId): mixed
    {
        $stored = self::load($tenantId);

        return array_key_exists($key, $stored) ? $stored[$key] : config($key);
    }

    /** @return array<string, mixed> semua key yang sah, sudah digabung default */
    public static function all(int $tenantId): array
    {
        return collect(array_keys(self::DEFINITIONS))
            ->mapWithKeys(fn (string $key) => [$key => self::get($key, $tenantId)])
            ->all();
    }

    /** @param array<string, mixed> $values hanya key di DEFINITIONS (sudah divalidasi) */
    public static function put(int $tenantId, array $values): void
    {
        foreach (array_intersect_key($values, self::DEFINITIONS) as $key => $value) {
            DB::table('tenant_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /** @return array<string, mixed> */
    private static function load(int $tenantId): array
    {
        return DB::table('tenant_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))
            ->all();
    }
}
