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
use Illuminate\Support\Carbon;
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

        Aturan H-7 hanya ditegakkan bila `planned_check_in` diisi — tanpa
        tanggal rencana menginap, tidak ada yang bisa dihitung mundur.

        Sesi diturunkan dari jam yang diisi: 09:30 masuk sesi Pagi, sehingga
        jadwal ini ikut memakan kuota slot yang ditawarkan ke customer. Jam di
        luar jam operasional tidak menutup slot mana pun.
        TXT,
        responses: [
            new OA\Response(response: 201, description: 'Survey dijadwalkan'),
            new OA\Response(response: 422, description: 'Validasi gagal / melewati batas H-7', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $date = $request->string('scheduled_date')->toString();
        $time = $request->string('scheduled_time')->toString();

        if ($error = $this->validateAgainstDeadline($request->input('planned_check_in'), $date)) {
            return $error;
        }

        $survey = Survey::create([
            'category_id' => $request->integer('category_id'),
            'item_id' => $request->input('item_id'),
            'guest_id' => $request->input('guest_id'),
            'guest_name' => $request->string('guest_name')->toString(),
            'planned_check_in' => $request->input('planned_check_in'),
            'scheduled_date' => $date,
            'scheduled_time' => $time,
            'session' => SurveySlots::sessionForTime($time),
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
            'status', 'report', 'scheduled_date', 'scheduled_time', 'pic_user_id', 'notes',
        ]);

        if (array_key_exists('scheduled_date', $data)) {
            $checkIn = $survey->booking?->check_in?->toDateString()
                ?? $survey->planned_check_in?->toDateString();

            if ($error = $this->validateAgainstDeadline($checkIn, $data['scheduled_date'])) {
                return $error;
            }
        }

        // Jam berubah -> sesinya ikut berubah, kalau tidak kuota slot customer
        // dihitung berdasarkan sesi yang sudah tidak sesuai jamnya.
        if (array_key_exists('scheduled_time', $data)) {
            $data['session'] = SurveySlots::sessionForTime($data['scheduled_time']);
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
     * Batas H-7 hanya bisa ditegakkan bila tanggal rencana menginap diketahui.
     * Calon tamu yang menyurvei sebelum punya tanggal pasti tidak diblokir.
     */
    private function validateAgainstDeadline(?string $checkIn, string $scheduledDate): ?JsonResponse
    {
        if ($checkIn === null) {
            return null;
        }

        $deadline = SurveySlots::deadlineFor($checkIn);

        if (Carbon::parse($scheduledDate)->startOfDay()->greaterThan($deadline)) {
            return $this->error(
                'Survey harus dijadwalkan paling lambat '.$deadline->toDateString().' (H-7 sebelum check-in).',
                422,
            );
        }

        return null;
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
