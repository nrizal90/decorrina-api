<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Kode Benefit & Akses Kawasan (B6, Fase 6).
 *
 * Kode lahir otomatis saat booking ditandai DP/Lunas (BookingController::
 * updateStatus). Verifikasinya MANUAL oleh security di gerbang — backend hanya
 * menyimpan kode dan menandai statusnya, bukan gerbang digital.
 *
 * Belum ada payment gateway maupun WhatsApp API (M10), jadi "kirim ulang"
 * mengembalikan link wa.me berisi pesan siap kirim; admin yang menekan kirim.
 */
class BenefitCodeController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/admin/benefit-codes',
        tags: ['Benefit'],
        summary: 'Daftar kode akses kawasan (B6)',
        description: 'Hanya booking yang sudah punya kode (pernah DP/Lunas) dan tidak dibatalkan.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Cari kode booking ATAU nama tamu.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, description: 'Filter villa.', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'benefit_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['Belum Digunakan', 'Sudah Ditunjukkan'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar booking berkode akses (bentuk sama dengan Booking)'),
            new OA\Response(response: 403, description: 'Tidak punya permission benefits:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->with(['item.category', 'guest'])
            ->whereNotNull('kode_akses_kawasan')
            ->where('status', '!=', Booking::STATUS_DIBATALKAN)
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q
                    ->where('kode_booking', 'ilike', $term)
                    ->orWhereHas('guest', fn ($g) => $g->where('name', 'ilike', $term)));
            })
            ->when($request->filled('category_id'), fn ($q) => $q
                ->whereHas('item', fn ($i) => $i->where('category_id', $request->integer('category_id'))))
            ->when($request->input('benefit_status') === Booking::BENEFIT_BELUM, fn ($q) => $q->whereNull('benefit_shown_at'))
            ->when($request->input('benefit_status') === Booking::BENEFIT_SUDAH, fn ($q) => $q->whereNotNull('benefit_shown_at'))
            ->latest('check_in')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(BookingResource::collection($bookings));
    }

    #[OA\Patch(
        path: '/api/admin/benefit-codes/{booking}',
        tags: ['Benefit'],
        summary: 'Tandai benefit sudah/belum ditunjukkan (B6)',
        description: '`shown: false` untuk membatalkan tanda yang salah klik.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['shown'],
            properties: [new OA\Property(property: 'shown', type: 'boolean', example: true)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Status benefit diperbarui'),
            new OA\Response(response: 422, description: 'Booking belum punya kode akses', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Booking $booking): JsonResponse
    {
        $request->validate(['shown' => ['required', 'boolean']]);

        if ($error = $this->requireCode($booking)) {
            return $error;
        }

        $booking->update(['benefit_shown_at' => $request->boolean('shown') ? now() : null]);

        return $this->ok(
            new BookingResource($booking->load(['item.category', 'guest'])),
            'Status benefit diperbarui',
        );
    }

    #[OA\Post(
        path: '/api/admin/benefit-codes/{booking}/resend',
        tags: ['Benefit'],
        summary: 'Kirim ulang kode ke tamu (B6)',
        description: 'Belum ada WhatsApp API: mengembalikan `url` wa.me berisi pesan siap kirim untuk dibuka admin.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{ channel: "whatsapp_link", url, message }'),
            new OA\Response(response: 422, description: 'Belum punya kode akses / tamu tanpa nomor WhatsApp', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function resend(Booking $booking): JsonResponse
    {
        if ($error = $this->requireCode($booking)) {
            return $error;
        }

        $booking->load(['item.category', 'guest']);
        $phone = self::waNumber((string) $booking->guest?->phone);

        if ($phone === '') {
            return $this->error('Tamu ini tidak punya nomor WhatsApp.', 422);
        }

        $message = sprintf(
            "Halo %s,\n\nBerikut kode akses kawasan untuk booking %s di %s (check-in %s):\n\n*%s*\n\n"
                ."Tunjukkan kode ini kepada petugas keamanan di Gerbang Gunungsari.\n\nTerima kasih — De'Corrinna",
            $booking->guest?->name,
            $booking->kode_booking,
            $booking->item?->category?->name ?? $booking->item?->name,
            $booking->check_in->translatedFormat('d M Y'),
            $booking->kode_akses_kawasan,
        );

        return $this->ok([
            'channel' => 'whatsapp_link',
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            'message' => $message,
        ]);
    }

    private function requireCode(Booking $booking): ?JsonResponse
    {
        if ($booking->kode_akses_kawasan === null || $booking->status === Booking::STATUS_DIBATALKAN) {
            return $this->error('Booking ini belum/tidak punya kode akses kawasan.', 422);
        }

        return null;
    }

    /** wa.me butuh format internasional tanpa "+": 0812… -> 62812…. */
    private static function waNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
