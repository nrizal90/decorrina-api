<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/**
 * Pengaturan tenant yang bisa diubah admin (Settings > Kebijakan Operasional).
 * Diproteksi `settings:index` / `settings:update` via `rbac`.
 *
 * Bentuk data bersarang per grup, mis. {"reschedule": {"quota": 1, ...}}.
 * Key yang sah dan validasinya: App\Support\TenantSettings::DEFINITIONS.
 */
class SettingsController extends Controller
{
    #[OA\Get(
        path: '/api/settings',
        tags: ['Settings'],
        summary: 'Pengaturan tenant (nilai tersimpan, atau default bila belum diatur)',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Pengaturan')]
    )]
    public function index(): JsonResponse
    {
        if ($error = $this->requireTenant()) {
            return $error;
        }

        return $this->ok(Arr::undot(TenantSettings::all(app('currentTenantId'))));
    }

    #[OA\Put(
        path: '/api/settings',
        tags: ['Settings'],
        summary: 'Simpan pengaturan tenant',
        description: 'Kirim satu grup utuh, mis. {"reschedule": {"quota": 2, "deadline_days": 3, "allow_change_nights": false, "allow_change_item": false}}. Grup yang tidak dikirim tidak berubah.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Tersimpan'),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        if ($error = $this->requireTenant()) {
            return $error;
        }

        // Validasi hanya grup yang dikirim; `required` berlaku di dalam grup itu.
        $rules = array_filter(
            TenantSettings::DEFINITIONS,
            fn (string $key) => $request->has(explode('.', $key)[0]),
            ARRAY_FILTER_USE_KEY,
        );

        $validated = $request->validate($rules, [
            'reschedule.quota.*' => 'Kuota reschedule harus angka 0–10.',
            'reschedule.deadline_days.*' => 'Batas pengajuan harus angka 0–90 hari.',
        ]);

        TenantSettings::put(app('currentTenantId'), Arr::dot($validated));

        return $this->ok(Arr::undot(TenantSettings::all(app('currentTenantId'))), 'Pengaturan tersimpan');
    }

    /** Superadmin Azatech tanpa header X-Tenant tidak punya tenant tujuan. */
    private function requireTenant(): ?JsonResponse
    {
        return app()->bound('currentTenantId')
            ? null
            : $this->error('Pilih tenant terlebih dahulu (header X-Tenant).', 422);
    }
}
