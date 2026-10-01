<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBookingResource;
use App\Models\Booking;
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
            ->with(['item.category', 'guest', 'addons.addon'])
            ->latest()
            ->get();

        return $this->ok(PublicBookingResource::collection($bookings));
    }
}
