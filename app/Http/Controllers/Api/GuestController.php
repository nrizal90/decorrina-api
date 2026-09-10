<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestResource;
use App\Models\Booking;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Profil Tamu / CRM (B8, Fase 7) — hanya BACA.
 *
 * Tamu tidak dibuat dari layar ini. Barisnya lahir otomatis dari booking
 * (`BookingCreator::resolveGuest`): nomor WhatsApp yang sama berarti tamu yang
 * sama dan booking-nya bertambah; nomor baru berarti baris baru. Layar ini
 * hanya memperlihatkan hasilnya.
 */
class GuestController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/guests',
        tags: ['CRM'],
        summary: 'Daftar tamu (B8)',
        description: <<<'TXT'
        Daftar tamu terpaginasi beserta jumlah booking dan total pengeluaran.

        `origins` ikut dikembalikan — daftar asal daerah yang benar-benar ada di
        data, untuk mengisi dropdown filter tanpa panggilan kedua.
        TXT,
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Cari nama, email, atau nomor.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'guest_type', in: 'query', description: 'Pribadi | Instansi', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'origin', in: 'query', description: 'Asal daerah (cocok persis).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar tamu'),
            new OA\Response(response: 403, description: 'Tidak punya permission guests:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $guests = $this->withAggregates(Guest::query())
            ->with('latestBooking')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term));
            })
            ->when($request->filled('guest_type'), fn ($q) => $q->where('guest_type', $request->string('guest_type')))
            ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->string('origin')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(
            GuestResource::collection($guests),
            'OK',
            ['origins' => $this->origins()],
        );
    }

    #[OA\Get(
        path: '/api/guests/{guest}',
        tags: ['CRM'],
        summary: 'Detail tamu beserta riwayat booking (B8)',
        responses: [
            new OA\Response(response: 200, description: 'Detail tamu'),
            new OA\Response(response: 404, description: 'Tamu tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Guest $guest): JsonResponse
    {
        $guest = $this->withAggregates(Guest::query())
            ->with([
                'latestBooking',
                'bookings' => fn ($q) => $q->with('item.category')->orderByDesc('check_in'),
            ])
            ->findOrFail($guest->id);

        return $this->ok(new GuestResource($guest));
    }

    /**
     * Jumlah booking dan total pengeluaran dihitung di query. Pengeluaran
     * mengabaikan booking yang dibatalkan — uangnya tidak pernah masuk.
     */
    private function withAggregates(Builder $query): Builder
    {
        return $query
            ->withCount('bookings')
            ->withSum(
                ['bookings as total_spent' => fn ($q) => $q->whereNotIn('status', Booking::RELEASING_STATUSES)],
                'total',
            );
    }

    /**
     * Asal daerah yang benar-benar ada di data — bukan daftar kota tetap,
     * yang akan menawarkan filter tanpa hasil dan melewatkan kota yang ada.
     *
     * @return array<int, string>
     */
    private function origins(): array
    {
        return Guest::query()
            ->whereNotNull('origin')
            ->where('origin', '!=', '')
            ->distinct()
            ->orderBy('origin')
            ->pluck('origin')
            ->all();
    }
}
