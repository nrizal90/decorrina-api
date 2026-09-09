<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Villa\IndexVillaRequest;
use App\Http\Resources\VillaDetailResource;
use App\Http\Resources\VillaResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Katalog publik (A2 & A3). Tanpa autentikasi dan TANPA middleware `rbac` —
 * tenant ditentukan PublicTenantMiddleware dari header `X-Tenant`.
 *
 * "Villa" di sini adalah `Category`; item = kamar/paket di dalamnya.
 */
class VillaController extends Controller
{
    #[OA\Get(
        path: '/api/villas',
        tags: ['Katalog Publik'],
        summary: 'Daftar villa (A2)',
        description: <<<'TXT'
        Katalog villa untuk halaman listing. Tidak butuh autentikasi.

        Kategori nonaktif ATAU yang belum punya item aktif tetap dikembalikan,
        ditandai `coming_soon: true` dengan `price_from: null` — frontend
        menampilkannya diredupkan dengan label "Segera hadir" (lihat kartu
        Wisata Camping). Menyaringnya di server akan menghilangkan kartu itu.

        `price_from` dan `capacity` dihitung dari item aktif, bukan kolom
        tersimpan di kategori.

        Filter `cap_*` dan `check_in` digabung pada item yang SAMA: villa lolos
        bila punya satu item aktif yang muat rombongan itu sekaligus bebas pada
        tanggal itu. Perlu diketahui, memakai filter apa pun akan menyingkirkan
        kartu `coming_soon` — kategori tanpa item aktif tidak bisa memenuhi
        syarat mana pun.
        TXT,
        parameters: [
            new OA\Parameter(
                name: 'X-Tenant',
                in: 'header',
                description: 'Slug tenant. Bila kosong dipakai tenant default dari konfigurasi.',
                schema: new OA\Schema(type: 'string', example: 'decorinna')
            ),
            new OA\Parameter(name: 'cap_min', in: 'query', description: 'Filter kapasitas minimum (dari dropdown "Kapasitas tamu").', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'cap_max', in: 'query', description: 'Filter kapasitas maksimum.', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'check_in', in: 'query', description: 'Filter ketersediaan: hanya villa yang punya item aktif bebas mulai tanggal ini (format Y-m-d).', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-24')),
            new OA\Parameter(name: 'check_out', in: 'query', description: 'Akhir rentang ketersediaan. Bila kosong dipakai satu malam sesudah check_in.', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-26')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar villa',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Villa')),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Tenant tidak ditemukan/tidak aktif', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(IndexVillaRequest $request): JsonResponse
    {
        $hasFilter = $request->filled('cap_min') || $request->filled('cap_max') || $request->hasDateFilter();

        // Kapasitas dan tanggal disaring dalam SATU whereHas, bukan dua yang
        // berdampingan: syaratnya "ada item aktif yang muat rombongan itu DAN
        // bebas pada tanggal itu". Dua whereHas terpisah akan meloloskan villa
        // yang satu kamarnya muat tapi terisi, dan kamar lain kosong tapi
        // terlalu kecil — persis yang tidak bisa dipesan pengunjung.
        //
        // Lewat whereHas, bukan HAVING atas alias agregat: yang terakhir tidak
        // sah di Postgres tanpa GROUP BY.
        $villas = $this->withCatalogAggregates(Category::query())
            ->when($hasFilter, fn ($q) => $q->whereHas('activeItems', function ($item) use ($request) {
                $item
                    ->when($request->filled('cap_min'), fn ($i) => $i->where('cap_max', '>=', $request->integer('cap_min')))
                    ->when($request->filled('cap_max'), fn ($i) => $i->where('cap_min', '<=', $request->integer('cap_max')))
                    // Aturan bentroknya milik Booking (scope blocking +
                    // overlapping), tidak ditulis ulang di sini — termasuk
                    // bahwa check-out hari X tidak menghalangi check-in hari X.
                    ->when($request->hasDateFilter(), fn ($i) => $i->whereDoesntHave(
                        'bookings',
                        fn ($booking) => $booking
                            ->blocking()
                            ->overlapping($request->string('check_in')->toString(), $request->checkOut()),
                    ));
            }))
            ->orderByRaw("CASE WHEN status = 'Aktif' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return $this->ok(VillaResource::collection($villas));
    }

    #[OA\Get(
        path: '/api/villas/{slug}',
        tags: ['Katalog Publik'],
        summary: 'Detail villa (A3)',
        description: 'Detail villa beserta fasilitas, item aktif (tarif weekday/weekend), dan add-on yang tertaut. Add-on disertakan di sini supaya layar pilih add-on (A7) tidak perlu panggilan terpisah.',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'villa-de-corrinna')),
            new OA\Parameter(name: 'X-Tenant', in: 'header', schema: new OA\Schema(type: 'string', example: 'decorinna')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail villa', content: new OA\JsonContent(ref: '#/components/schemas/VillaDetail')),
            new OA\Response(response: 404, description: 'Villa atau tenant tidak ditemukan', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(string $slug): JsonResponse
    {
        $villa = $this->withCatalogAggregates(Category::query())
            ->with([
                'facilities' => fn ($q) => $q->where('status', 'Aktif')->orderBy('name'),
                'activeItems' => fn ($q) => $q->orderBy('price_weekday'),
                'addons' => fn ($q) => $q->where('status', 'Aktif')->orderBy('name'),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->ok(new VillaDetailResource($villa));
    }

    /**
     * Harga "Mulai dari" dan rentang kapasitas diturunkan dari item AKTIF.
     * Dipakai bersama oleh index & show agar keduanya konsisten.
     */
    private function withCatalogAggregates(Builder $query): Builder
    {
        return $query
            ->withMin('activeItems as price_from', 'price_weekday')
            ->withMin('activeItems as cap_min', 'cap_min')
            ->withMax('activeItems as cap_max', 'cap_max');
    }
}
