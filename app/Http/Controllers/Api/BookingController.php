<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CheckAvailabilityRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Item;
use App\Support\BookingAvailability;
use App\Support\BookingCode;
use App\Support\BookingPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Booking (Fase 3) — daftar, detail, booking manual admin, dan ubah status.
 *
 * Alur booking customer (A4–A10) menyusul; endpoint di sini adalah jalur admin
 * B3 plus pengecekan ketersediaan yang dipakai keduanya.
 */
class BookingController extends Controller
{
    use ApiResponses;

    #[OA\Get(
        path: '/api/bookings',
        tags: ['Booking'],
        summary: 'Daftar booking (B3)',
        description: 'Papan booking admin. Diurutkan dari check-in terbaru.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Cari pada kode booking ATAU nama tamu.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['Menunggu Pembayaran', 'DP Dibayar', 'Lunas', 'Selesai', 'Dibatalkan'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Batas bawah tanggal CHECK-IN.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Batas atas tanggal CHECK-IN.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar booking terpaginasi',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Booking')),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission bookings:index', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->with(['item.category', 'guest'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q
                    ->where('kode_booking', 'ilike', $term)
                    ->orWhereHas('guest', fn ($g) => $g->where('name', 'ilike', $term)));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Rentang tanggal disaring atas dasar check-in — itu yang dilihat
            // admin di papan booking ("tamu yang datang antara tanggal ini").
            ->when($request->filled('from'), fn ($q) => $q->whereDate('check_in', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('check_in', '<=', $request->date('to')))
            ->latest('check_in')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated(BookingResource::collection($bookings));
    }

    #[OA\Get(
        path: '/api/bookings/{booking}',
        tags: ['Booking'],
        summary: 'Detail booking',
        description: 'Termasuk rincian add-on beserta harga snapshot-nya.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detail booking',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Booking')]
                )
            ),
            new OA\Response(response: 404, description: 'Tidak ditemukan (termasuk milik tenant lain)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Booking $booking): JsonResponse
    {
        return $this->ok(
            new BookingResource($booking->load(['item.category', 'guest', 'addons.addon'])),
        );
    }

    /**
     * Cek ketersediaan tanggal untuk sebuah item.
     */
    #[OA\Get(
        path: '/api/bookings/availability',
        tags: ['Booking'],
        summary: 'Cek ketersediaan tanggal (A4)',
        description: 'Satu item hanya bisa ditempati satu booking pada rentang yang sama. Booking berstatus `Dibatalkan` melepaskan tanggalnya. Check-out hari X TIDAK bentrok dengan check-in hari X. Memakai permission `bookings:index`.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'item_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'check_in', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'check_out', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hasil pengecekan',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'available', type: 'boolean', example: false),
                                new OA\Property(
                                    property: 'conflicts',
                                    type: 'array',
                                    description: 'Booking yang menghalangi, agar UI bisa menyebut tanggal mana yang bentrok.',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1),
                                            new OA\Property(property: 'kode_booking', type: 'string', example: 'DCG-2026-00123'),
                                            new OA\Property(property: 'check_in', type: 'string', format: 'date'),
                                            new OA\Property(property: 'check_out', type: 'string', format: 'date'),
                                            new OA\Property(property: 'status', type: 'string'),
                                        ]
                                    )
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validasi gagal', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function availability(CheckAvailabilityRequest $request): JsonResponse
    {
        $conflicts = BookingAvailability::conflicts(
            $request->integer('item_id'),
            $request->string('check_in')->toString(),
            $request->string('check_out')->toString(),
        );

        return $this->ok([
            'available' => $conflicts->isEmpty(),
            // Disertakan agar admin tahu tanggal mana yang menghalangi,
            // bukan sekadar "tidak tersedia".
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * Booking manual oleh admin (B3).
     */
    #[OA\Post(
        path: '/api/bookings',
        tags: ['Booking'],
        summary: 'Booking manual oleh admin (B3)',
        description: 'Membuat booking beserta tamunya. Backend menyalin harga & kebijakan pembayaran dari item sebagai snapshot, membuat `kode_booking`, lalu menyimpan status awal `Menunggu Pembayaran`. Ditolak 422 bila item nonaktif, `pax` di luar kapasitas item, atau tanggalnya bentrok dengan booking lain.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BookingInput')),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Booking dibuat',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Booking')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validasi gagal, item nonaktif, kapasitas tidak cocok, atau tanggal bentrok',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')],
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Tanggal tersebut sudah terisi booking lain: DCG-2026-00123 (21 Aug 2026 s.d. 24 Aug 2026)')]
                )
            ),
        ]
    )]
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $item = Item::findOrFail($request->integer('item_id'));
        $checkIn = $request->string('check_in')->toString();
        $checkOut = $request->string('check_out')->toString();

        if ($error = $this->validateStay($item, $checkIn, $checkOut, $request->integer('pax'))) {
            return $error;
        }

        if ($error = $this->validateAddons($item, $request->input('addons', []))) {
            return $error;
        }

        $booking = DB::transaction(function () use ($request, $item, $checkIn, $checkOut) {
            $stay = BookingPricing::forStay($item, $checkIn, $checkOut);

            $addonLines = $request->input('addons', []);
            $addons = Addon::whereIn('id', array_column($addonLines, 'addon_id'))->get()->keyBy('id');
            $addonPricing = BookingPricing::forAddons($addonLines, $addons);

            $guest = $this->resolveGuest($request);

            $booking = Booking::create([
                'item_id' => $item->id,
                'guest_id' => $guest->id,
                'kode_booking' => BookingCode::generate(app('currentTenantId')),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $stay['nights'],
                'pax' => $request->integer('pax'),
                'vehicle_count' => $request->input('vehicle_count'),
                // Snapshot harga & kebijakan pembayaran item saat ini.
                'price_weekday' => $item->price_weekday,
                'price_weekend' => $item->price_weekend,
                'subtotal_item' => $stay['subtotal'],
                'subtotal_addons' => $addonPricing['subtotal'],
                'total' => $stay['subtotal'] + $addonPricing['subtotal'],
                'payment_mode' => $item->payment_mode,
                'dp_minimum' => $item->dp_minimum,
                'status' => Booking::STATUS_MENUNGGU,
                'source' => 'admin',
                'notes' => $request->input('notes'),
            ]);

            foreach ($addonPricing['lines'] as $line) {
                $booking->addons()->create($line);
            }

            return $booking;
        });

        return $this->created(
            new BookingResource($booking->load(['item.category', 'guest', 'addons.addon'])),
            'Booking berhasil dibuat',
        );
    }

    /**
     * Ubah status booking mengikuti state machine.
     */
    #[OA\Patch(
        path: '/api/bookings/{booking}/status',
        tags: ['Booking'],
        summary: 'Ubah status booking',
        description: "Perpindahan status mengikuti state machine:\n\n- `Menunggu Pembayaran` ke DP Dibayar / Lunas / Dibatalkan\n- `DP Dibayar` ke Lunas / Dibatalkan\n- `Lunas` ke Selesai / Dibatalkan\n- `Selesai` dan `Dibatalkan` bersifat FINAL\n\nTujuan yang sah untuk sebuah booking bisa dibaca dari field `allowed_transitions` pada responsnya.",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'booking', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['Menunggu Pembayaran', 'DP Dibayar', 'Lunas', 'Selesai', 'Dibatalkan'], example: 'DP Dibayar')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status diperbarui',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Booking')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Perpindahan status tidak sah',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')],
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Status tidak bisa berpindah dari "Lunas" ke "DP Dibayar". Yang diizinkan: Selesai, Dibatalkan.')]
                )
            ),
            new OA\Response(response: 403, description: 'Tidak punya permission bookings:update-status', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        $target = $request->string('status')->toString();

        if ($booking->status === $target) {
            return $this->error("Booking sudah berstatus \"{$target}\".", 422);
        }

        if (! $booking->canTransitionTo($target)) {
            $allowed = Booking::TRANSITIONS[$booking->status] ?? [];

            return $this->error(
                $allowed === []
                    ? "Booking berstatus \"{$booking->status}\" sudah final dan tidak bisa diubah lagi."
                    : "Status tidak bisa berpindah dari \"{$booking->status}\" ke \"{$target}\". Yang diizinkan: ".implode(', ', $allowed).'.',
                422,
            );
        }

        $booking->update(['status' => $target]);

        return $this->ok(
            new BookingResource($booking->load(['item.category', 'guest'])),
            'Status booking berhasil diperbarui',
        );
    }

    /**
     * Aturan yang tak bisa diungkapkan sebagai rule validasi biasa karena
     * bergantung pada item yang dipilih.
     */
    private function validateStay(Item $item, string $checkIn, string $checkOut, int $pax): ?JsonResponse
    {
        if ($item->status !== 'Aktif') {
            return $this->error('Item ini tidak aktif dan tidak bisa dipesan.', 422);
        }

        // Menonaktifkan villa berarti seluruh kamarnya ikut ditutup — item
        // aktif di bawah kategori nonaktif tetap tidak boleh dipesan. FE sudah
        // menyaringnya, tapi tanpa cek ini pemanggilan API langsung masih lolos.
        if ($item->category !== null && $item->category->status !== 'Aktif') {
            return $this->error('Villa untuk item ini sedang tidak aktif dan tidak bisa dipesan.', 422);
        }

        if ($pax < $item->cap_min || $pax > $item->cap_max) {
            return $this->error(
                "Jumlah tamu harus antara {$item->cap_min} dan {$item->cap_max} orang untuk item ini.",
                422,
            );
        }

        $conflicts = BookingAvailability::conflicts($item->id, $checkIn, $checkOut);

        if ($conflicts->isNotEmpty()) {
            return $this->error(
                'Tanggal tersebut sudah terisi booking lain: '.$conflicts
                    ->map(fn ($c) => sprintf(
                        '%s (%s s.d. %s)',
                        $c->kode_booking,
                        $c->check_in->format('d M Y'),
                        $c->check_out->format('d M Y'),
                    ))
                    ->implode(', '),
                422,
            );
        }

        return null;
    }

    /**
     * Add-on hanya boleh dipesan bila BERSTATUS AKTIF dan BERLAKU untuk item
     * yang dipilih — yaitu tertaut langsung ke item itu, atau ke kategorinya
     * (berlaku untuk seluruh kamar di villa tersebut).
     *
     * Tanpa ini tamu bisa ditagih untuk add-on yang tidak ditawarkan, atau
     * milik villa lain. Layar admin sudah menyaringnya, tapi itu kenyamanan;
     * penjagaan sebenarnya harus di sini.
     *
     * @param  array<int, array{addon_id: int, qty?: int}>  $lines
     */
    private function validateAddons(Item $item, array $lines): ?JsonResponse
    {
        if ($lines === []) {
            return null;
        }

        $requested = array_unique(array_column($lines, 'addon_id'));

        $allowed = Addon::whereIn('id', $requested)
            ->where('status', 'Aktif')
            ->where(fn ($query) => $query
                ->whereHas('items', fn ($q) => $q->whereKey($item->id))
                ->orWhereHas('categories', fn ($q) => $q->whereKey($item->category_id)))
            ->pluck('id')
            ->all();

        $rejected = array_diff($requested, $allowed);

        if ($rejected !== []) {
            $names = Addon::whereIn('id', $rejected)->pluck('name')->implode(', ');

            return $this->error(
                "Add-on berikut tidak bisa dipesan untuk item ini (nonaktif atau tidak tertaut): {$names}.",
                422,
            );
        }

        return null;
    }

    /**
     * Cari tamu berdasar nomor WhatsApp dalam tenant ini, atau buat baru.
     * Nomor jadi kunci alami supaya tamu berulang tidak terpecah jadi banyak
     * baris di CRM (Fase 7).
     */
    private function resolveGuest(StoreBookingRequest $request): Guest
    {
        $phone = $request->input('guest_phone');

        if ($phone) {
            $existing = Guest::where('phone', $phone)->first();

            if ($existing) {
                // Lengkapi data yang sebelumnya kosong, jangan menimpa yang ada.
                // Tamu yang kembali memesan tidak boleh kehilangan profil lamanya
                // hanya karena form kali ini dibiarkan kosong.
                $existing->fill(array_filter([
                    'name' => $existing->name ?: $request->input('guest_name'),
                    'email' => $existing->email ?: $request->input('guest_email'),
                    'birth_date' => $existing->birth_date ?: $request->input('guest_birth_date'),
                    'origin' => $existing->origin ?: $request->input('guest_origin'),
                    'guest_type' => $existing->guest_type ?: $request->input('guest_type'),
                ]))->save();

                return $existing;
            }
        }

        return Guest::create([
            'name' => $request->input('guest_name'),
            'phone' => $phone,
            'email' => $request->input('guest_email'),
            'birth_date' => $request->input('guest_birth_date'),
            'origin' => $request->input('guest_origin'),
            'guest_type' => $request->input('guest_type'),
        ]);
    }
}
