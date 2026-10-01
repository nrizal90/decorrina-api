<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\UpdateSurveyRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\User;
use App\Support\SurveySlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Manajemen Survey (B4). Survey dari jalur customer (A5) ikut tampil di sini —
 * keduanya baris di tabel yang sama, hanya cara pembuatannya berbeda.
 *
 * Tidak ada endpoint `show`: seeder permission tidak punya `surveys:show`, dan
 * modal detail di layar memakai data baris yang sudah ada di tabel. Membuat
 * rutenya hanya akan menghasilkan 403 yang membingungkan (fail-closed).
 */
class SurveyController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/surveys',
        tags: ['Survey'],
        summary: 'Daftar survey (B4)',
        description: <<<'TXT'
        Daftar survey terpaginasi untuk papan Manajemen Survey.

        `pic_options` ikut dikembalikan: dropdown "PIC Room Tour" butuh daftar
        user internal, sementara role Admin sengaja TIDAK punya `users:index`
        (manajemen user hanya untuk Superadmin Klien). Menyertakannya di sini
        membuat layar cukup berbekal `surveys:index`.
        TXT,
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Terjadwal | Menunggu Laporan | Selesai | Dibatalkan', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', description: 'Cari nama tamu.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar survey'),
            new OA\Response(response: 403, description: 'Tidak punya permission surveys:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $surveys = Survey::query()
            ->with(['category', 'item', 'guest', 'booking', 'pic'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q
                    ->where('guest_name', 'ilike', $term)
                    ->orWhereHas('guest', fn ($g) => $g->where('name', 'ilike', $term)));
            })
            // Yang paling dekat jadwalnya lebih dulu — papan ini dipakai untuk
            // menyiapkan kunjungan, bukan menelusuri riwayat.
            ->orderByDesc('scheduled_date')
            ->orderByDesc('scheduled_time')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(
            SurveyResource::collection($surveys),
            'OK',
            ['pic_options' => $this->picOptions()],
        );
    }

    #[OA\Post(
        path: '/api/surveys',
        tags: ['Survey'],
        summary: 'Jadwalkan survey (B4)',
        description: <<<'TXT'
        Menjadwalkan survey dari sisi admin, untuk calon tamu yang menghubungi
        langsung. Booking dan item boleh kosong.

        Aturan sama dengan jalur customer (CR-07): jam harus di dalam jendela
        villa (07:00–20:00 kosong, 12:00–14:00 ada tamu) dan tidak bertumpuk
        dengan survey lain di villa yang sama. Batas H-1 hanya ditegakkan bila
        `planned_check_in` diisi. Admin tidak terkena jeda H+2.
        TXT,
        responses: [
            new OA\Response(response: 201, description: 'Survey dijadwalkan'),
            new OA\Response(response: 422, description: 'Validasi gagal / jam bentrok / di luar jendela villa', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $date = $request->string('scheduled_date')->toString();
        $time = $request->string('scheduled_time')->toString();
        $endTime = $request->string('scheduled_end_time')->toString();

        $rejection = SurveySlots::rejectionFor(
            $request->integer('category_id'), $date, $time, $endTime,
            $request->input('planned_check_in'), enforceLeadTime: false,
        );

        if ($rejection !== null) {
            return $this->error($rejection, 422);
        }

        $survey = Survey::create([
            'category_id' => $request->integer('category_id'),
            'item_id' => $request->input('item_id'),
            'guest_id' => $request->input('guest_id'),
            'guest_name' => $request->string('guest_name')->toString(),
            'planned_check_in' => $request->input('planned_check_in'),
            'scheduled_date' => $date,
            'scheduled_time' => $time,
            'scheduled_end_time' => $endTime,
            'pic_user_id' => $request->input('pic_user_id'),
            'status' => Survey::STATUS_TERJADWAL,
            'notes' => $request->input('notes'),
        ]);

        return $this->created(
            new SurveyResource($survey->load(['category', 'item', 'guest', 'booking', 'pic'])),
            'Survey dijadwalkan',
        );
    }

    #[OA\Patch(
        path: '/api/surveys/{survey}',
        tags: ['Survey'],
        summary: 'Ubah survey / tulis laporan (B4)',
        description: <<<'TXT'
        Dipakai dua hal: menulis laporan hasil survey, dan memindahkan jadwal
        atau mengganti PIC.

        Mengisi `report` tanpa menyebut `status` otomatis menandai survey
        `Selesai` — di layar, menulis laporan memang berarti kunjungannya sudah
        terjadi, dan meminta admin mengubah status secara terpisah hanya
        menyisakan baris yang laporannya ada tapi statusnya masih menggantung.
        TXT,
        responses: [
            new OA\Response(response: 200, description: 'Survey diperbarui'),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateSurveyRequest $request, Survey $survey): JsonResponse
    {
        $data = $request->safe()->only([
            'status', 'report', 'scheduled_date', 'scheduled_time', 'scheduled_end_time', 'pic_user_id', 'notes',
        ]);

        // Jadwal dipindah -> periksa ulang dengan nilai gabungan (yang tidak
        // dikirim tetap memakai nilai lama), mengecualikan survey ini sendiri.
        if (array_intersect_key($data, array_flip(['scheduled_date', 'scheduled_time', 'scheduled_end_time']))) {
            $rejection = SurveySlots::rejectionFor(
                $survey->category_id,
                $data['scheduled_date'] ?? $survey->scheduled_date->toDateString(),
                $data['scheduled_time'] ?? substr((string) $survey->scheduled_time, 0, 5),
                $data['scheduled_end_time'] ?? substr((string) $survey->scheduled_end_time, 0, 5),
                $survey->booking?->check_in?->toDateString() ?? $survey->planned_check_in?->toDateString(),
                enforceLeadTime: false,
                exceptSurveyId: $survey->id,
            );

            if ($rejection !== null) {
                return $this->error($rejection, 422);
            }
        }

        if (! empty($data['report']) && ! array_key_exists('status', $data)) {
            $data['status'] = Survey::STATUS_SELESAI;
        }

        $survey->update($data);

        return $this->ok(
            new SurveyResource($survey->fresh()->load(['category', 'item', 'guest', 'booking', 'pic'])),
            'Survey diperbarui',
        );
    }

    /**
     * Kandidat PIC = user internal tenant ini yang masih aktif. Customer punya
     * `tenant_id` NULL sehingga otomatis tidak ikut terbawa.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function picOptions(): array
    {
        return User::query()
            ->where('status', 'Aktif')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
