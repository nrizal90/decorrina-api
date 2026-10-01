<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBookingResource;
use App\Http\Resources\RescheduleResource;
use App\Models\Booking;
use App\Models\Item;
use App\Support\Rescheduler;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Riwayat Transaksi customer (A13).
 *
 * Hanya booking yang dibuat SAMBIL LOGIN (`bookings.user_id`). Booking anonim
 * sengaja tidak dicocokkan lewat nomor HP: tanpa verifikasi (OTP), siapa pun
 * bisa mengklaim booking orang lain (audit T-03b). Booking anonim tetap bisa
 * dicek lewat /public/bookings/lookup.
 *
 * Filter `user_id` dipasang langsung, bukan lewat OwnershipScope: endpoint ini
 * "milik saya" untuk role apa pun, bukan daftar tenant yang disaring per role.
 */
class MyBookingController extends Controller
{
    #[OA\Get(
        path: '/api/me/bookings',
        tags: ['Customer'],
        summary: 'Riwayat booking milik akun yang login (A13)',
        description: 'Terbaru dulu. Hanya booking yang dibuat sambil login.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar booking'),
            new OA\Response(response: 401, description: 'Belum login', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        // ponytail: tanpa paginasi — satu akun hanya punya segelintir booking. Paginate bila ada yang ratusan.
        $bookings = Booking::query()
            // Tanpa tenant scope: akun customer lintas tenant (tenant_id NULL).
            ->withoutGlobalScope('tenant')
            ->where('user_id', $request->user()->id)
            ->with(['item.category', 'guest', 'addons.addon', 'latestReschedule.oldItem', 'latestReschedule.newItem'])
            ->latest()
            ->get();

        return $this->ok(PublicBookingResource::collection($bookings));
    }

    #[OA\Get(
        path: '/api/me/bookings/{booking}/reschedule',
        tags: ['Customer'],
        summary: 'Aturan & pratinjau reschedule booking milik sendiri (A13)',
        description: 'Tanpa tanggal: aturan (kuota, batas H-n, boleh ganti malam/kamar) dan alasan bila belum bisa. Dengan `check_in` & `check_out`: ikut pratinjau harga, selisih, dan ketersediaan.',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Aturan & pratinjau')]
    )]
    public function reschedule(Request $request, int $booking): JsonResponse
    {
        $owned = $this->owned($request, $booking);
        $data = $request->validate([
            'check_in' => ['nullable', 'date_format:Y-m-d'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in'],
            'item_id' => ['nullable', 'integer'],
        ]);

        $preview = null;
        if (! empty($data['check_in']) && ! empty($data['check_out'])) {
            $item = $this->targetItem($owned, $data['item_id'] ?? null);
            $preview = [
                ...Rescheduler::preview($owned, $item, $data['check_in'], $data['check_out']),
                'rejection' => Rescheduler::customerRejection($owned, $item, $data['check_in'], $data['check_out']),
            ];
        }

        return $this->ok([
            'booking' => new PublicBookingResource($owned),
            'policy' => Rescheduler::policy($owned),
            'blocker' => Rescheduler::customerBlocker($owned),
            // Pilihan kamar hanya bila config mengizinkan pindah kamar.
            'items' => TenantSettings::get('reschedule.allow_change_item', $owned->tenant_id)
                ? $owned->item->category->activeItems()->get(['id', 'name', 'cap_min', 'cap_max'])
                : [],
            'preview' => $preview,
        ]);
    }

    #[OA\Post(
        path: '/api/me/bookings/{booking}/reschedule',
        tags: ['Customer'],
        summary: 'Ajukan reschedule (menunggu persetujuan admin)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Pengajuan dibuat'),
            new OA\Response(response: 422, description: 'Melanggar aturan reschedule / tanggal bentrok', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function requestReschedule(Request $request, int $booking): JsonResponse
    {
        $owned = $this->owned($request, $booking);
        $data = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'item_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = $this->targetItem($owned, $data['item_id'] ?? null);

        if ($rejection = Rescheduler::customerRejection($owned, $item, $data['check_in'], $data['check_out'])) {
            return $this->error($rejection, 422);
        }

        $record = Rescheduler::request($owned, $item, $data['check_in'], $data['check_out'], $data['reason'] ?? null, $request->user());

        return $this->created(
            new RescheduleResource($record->load(['oldItem', 'newItem'])),
            'Pengajuan reschedule terkirim. Admin akan meninjaunya.',
        );
    }

    /** Booking milik pemanggil; 404 (bukan 403) supaya id orang lain tidak bisa ditebak ada. */
    private function owned(Request $request, int $id): Booking
    {
        return Booking::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $request->user()->id)
            ->with(['item.category', 'guest', 'addons.addon', 'latestReschedule.oldItem', 'latestReschedule.newItem'])
            ->findOrFail($id);
    }

    private function targetItem(Booking $booking, ?int $itemId): Item
    {
        // Tamu tanpa tenant aktif: item dicari di tenant booking itu sendiri.
        return Item::withoutGlobalScope('tenant')
            ->where('tenant_id', $booking->tenant_id)
            ->findOrFail($itemId ?? $booking->item_id);
    }
}
