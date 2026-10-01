<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RescheduleResource;
use App\Models\Booking;
use App\Models\Item;
use App\Models\Reschedule;
use App\Support\Ledger;
use App\Support\Rescheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Reschedule dari sisi admin (B3): langsung ubah, dan memutuskan pengajuan
 * tamu. Aturannya di App\Support\Rescheduler.
 */
class RescheduleController extends Controller
{
    #[OA\Get(
        path: '/api/reschedules',
        tags: ['Booking'],
        summary: 'Daftar pengajuan reschedule',
        description: 'Default hanya yang menunggu persetujuan; `status` untuk riwayat lain.',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Daftar pengajuan')]
    )]
    public function index(Request $request): JsonResponse
    {
        $records = Reschedule::query()
            ->where('status', $request->string('status')->toString() ?: Reschedule::STATUS_MENUNGGU)
            ->with(['booking.guest', 'oldItem', 'newItem'])
            ->latest()
            ->limit(100)
            ->get();

        return $this->ok(RescheduleResource::collection($records));
    }

    #[OA\Get(
        path: '/api/bookings/{booking}/reschedule-preview',
        tags: ['Booking'],
        summary: 'Pratinjau harga & ketersediaan tanggal baru (admin)',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Pratinjau')]
    )]
    public function preview(Request $request, Booking $booking): JsonResponse
    {
        [$item, $checkIn, $checkOut] = $this->target($request, $booking);

        return $this->ok([
            ...Rescheduler::preview($booking, $item, $checkIn, $checkOut),
            'rejection' => Rescheduler::adminRejection($booking, $item, $checkIn, $checkOut),
        ]);
    }

    #[OA\Post(
        path: '/api/bookings/{booking}/reschedule',
        tags: ['Booking'],
        summary: 'Reschedule langsung oleh admin',
        description: 'Langsung berlaku tanpa kuota/batas H-n. Harga dihitung ulang, survey yang belum terjadi dibatalkan.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Booking di-reschedule'),
            new OA\Response(response: 422, description: 'Tanggal bentrok / kapasitas / booking final', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request, Booking $booking): JsonResponse
    {
        [$item, $checkIn, $checkOut] = $this->target($request, $booking);

        if ($rejection = Rescheduler::adminRejection($booking, $item, $checkIn, $checkOut)) {
            return $this->error($rejection, 422);
        }

        $record = Rescheduler::applyByAdmin($booking, $item, $checkIn, $checkOut, $request->input('note'), $request->user());

        return $this->ok(new RescheduleResource($record), 'Booking berhasil di-reschedule');
    }

    #[OA\Post(
        path: '/api/reschedules/{reschedule}/approve',
        tags: ['Booking'],
        summary: 'Setujui pengajuan reschedule tamu',
        description: 'Ketersediaan diperiksa ULANG — tanggal bisa sudah diambil orang lain sejak diajukan.',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Disetujui')]
    )]
    public function approve(Request $request, Reschedule $reschedule): JsonResponse
    {
        if ($error = $this->ensurePending($reschedule)) {
            return $error;
        }

        $rejection = Rescheduler::adminRejection(
            $reschedule->booking,
            $reschedule->newItem,
            $reschedule->new_check_in->toDateString(),
            $reschedule->new_check_out->toDateString(),
        );

        if ($rejection !== null) {
            return $this->error($rejection, 422);
        }

        $record = Rescheduler::approve($reschedule, $request->user(), $request->input('note'));

        return $this->ok(new RescheduleResource($record), 'Reschedule disetujui');
    }

    #[OA\Post(
        path: '/api/reschedules/{reschedule}/reject',
        tags: ['Booking'],
        summary: 'Tolak pengajuan reschedule tamu',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Ditolak')]
    )]
    public function reject(Request $request, Reschedule $reschedule): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        if ($error = $this->ensurePending($reschedule)) {
            return $error;
        }

        return $this->ok(
            new RescheduleResource(Rescheduler::reject($reschedule, $request->user(), $request->input('note'))),
            'Pengajuan reschedule ditolak',
        );
    }

    #[OA\Post(
        path: '/api/reschedules/{reschedule}/difference-paid',
        tags: ['Booking'],
        summary: 'Tandai selisih reschedule sudah dibayar',
        description: 'Hanya untuk booking Lunas dengan selisih positif. Dicatat ke buku kas.',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Selisih tercatat dibayar')]
    )]
    public function differencePaid(Reschedule $reschedule): JsonResponse
    {
        $due = $reschedule->outstandingDifference();

        if ($due === 0) {
            return $this->error('Tidak ada selisih yang perlu dibayar untuk reschedule ini.', 422);
        }

        DB::transaction(function () use ($reschedule, $due) {
            $reschedule->update(['difference_paid_at' => now()]);
            Ledger::recordRescheduleDifference($reschedule->booking, $due);
        });

        return $this->ok(new RescheduleResource($reschedule->fresh(['booking', 'oldItem', 'newItem'])), 'Selisih tercatat dibayar');
    }

    /** @return array{0: Item, 1: string, 2: string} */
    private function target(Request $request, Booking $booking): array
    {
        $data = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'item_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = Item::findOrFail($data['item_id'] ?? $booking->item_id);

        return [$item, $data['check_in'], $data['check_out']];
    }

    private function ensurePending(Reschedule $reschedule): ?JsonResponse
    {
        return $reschedule->status === Reschedule::STATUS_MENUNGGU
            ? null
            : $this->error("Pengajuan ini sudah {$reschedule->status}.", 422);
    }
}
