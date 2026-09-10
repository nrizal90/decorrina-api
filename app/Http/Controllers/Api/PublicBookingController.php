<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\PublicStoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Item;
use App\Support\AddonPolicy;
use App\Support\BookingAvailability;
use App\Support\BookingCreator;
use App\Support\SurveySlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Booking yang dibuat sendiri oleh pengunjung (A6–A10).
 *
 * TANPA autentikasi: alur pemesanan menyediakan jalur "lanjutkan tanpa akun",
 * jadi menaruhnya di belakang `auth:sanctum` akan mematikan sebagian besar
 * pemesanan. Pengunjung yang kebetulan login tetap dikenali — tokennya dipakai
 * untuk menautkan booking ke akunnya, bukan untuk mengizinkan aksesnya.
 *
 * Booking selalu lahir berstatus "Menunggu Pembayaran". Sampai gateway
 * pembayaran (Fase 4) ada, ADMIN yang menandai lunas dari papan B3 — dan itu
 * tetap berguna setelahnya, sebagai jalan keluar ketika gateway bermasalah
 * atau tamu membayar lewat transfer manual.
 */
class PublicBookingController extends Controller
{
    use ApiResponses;

    public function store(PublicStoreBookingRequest $request): JsonResponse
    {
        $item = Item::find($request->integer('item_id'));

        if ($item === null || $item->status !== 'Aktif') {
            return $this->error('Item ini tidak tersedia untuk dipesan.', 422);
        }

        if ($item->category !== null && $item->category->status !== 'Aktif') {
            return $this->error('Villa untuk item ini sedang tidak aktif dan tidak bisa dipesan.', 422);
        }

        $checkIn = $request->string('check_in')->toString();
        $checkOut = $request->string('check_out')->toString();
        $pax = $request->integer('pax');

        if ($pax < $item->cap_min || $pax > $item->cap_max) {
            return $this->error(
                "Jumlah tamu harus antara {$item->cap_min} dan {$item->cap_max} orang untuk item ini.",
                422,
            );
        }

        // Tanggal bisa direbut orang lain antara layar pilih tanggal dan
        // penekanan tombol bayar — diperiksa lagi di sini, bukan dipercaya.
        if (! BookingAvailability::isAvailable($item->id, $checkIn, $checkOut)) {
            return $this->error('Tanggal tersebut baru saja terisi. Silakan pilih tanggal lain.', 422);
        }

        if ($error = $this->validatePaymentMode($item, $request->input('payment_mode'))) {
            return $error;
        }

        if ($error = $this->validateAddons($item, $request->input('addons', []))) {
            return $error;
        }

        if ($error = $this->validateSurvey($request->input('survey'), $checkIn)) {
            return $error;
        }

        $booking = BookingCreator::create(
            $item,
            $request->safe()->all(),
            'customer',
            // Token opsional: publik boleh anonim, tapi yang login ditautkan.
            auth('sanctum')->user(),
        );

        return $this->created(
            new BookingResource($booking->load(['item.category', 'guest', 'addons.addon'])),
            'Booking berhasil dibuat. Selesaikan pembayaran untuk mengonfirmasinya.',
        );
    }

    /**
     * Cek status booking (A15) untuk pengunjung tanpa akun.
     *
     * Kode booking SAJA tidak cukup: formatnya berurutan (DCG-2026-00001,
     * 00002, ...) sehingga bisa ditebak, dan isinya nama serta tanggal
     * menginap orang lain. Kontak yang dipakai saat memesan (telepon atau
     * email) harus ikut cocok. Ketidakcocokan apa pun dijawab dengan pesan
     * yang sama — jangan beri tahu penebak bahwa kodenya benar.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $code = strtoupper(trim($request->string('code')->toString()));
        $contact = self::normalizeContact($request->string('contact')->toString());

        $booking = Booking::query()
            ->with(['item.category', 'guest', 'addons.addon'])
            ->where('kode_booking', $code)
            ->first();

        $matches = $booking !== null && $booking->guest !== null && (
            self::normalizeContact((string) $booking->guest->phone) === $contact
            || self::normalizeContact((string) $booking->guest->email) === $contact
        );

        if (! $matches) {
            return $this->error('Kode booking atau kontak tidak cocok. Pastikan data yang dimasukkan benar.', 404);
        }

        return $this->ok(new BookingResource($booking));
    }

    /**
     * Telepon disamakan bentuknya sebelum dibandingkan: "0812-3456", "+62 812
     * 3456", dan "62 812 3456" adalah nomor yang sama. Email cukup huruf kecil.
     */
    private static function normalizeContact(string $value): string
    {
        $value = strtolower(trim($value));

        if (str_contains($value, '@')) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        // +62 / 62 -> 0, supaya cocok dengan cara orang menulis nomor lokal.
        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Mode pembayaran hanya boleh dipilih pengunjung bila item memang
     * menawarkan keduanya. Selain itu kebijakan item yang berlaku, dan
     * kiriman yang menyimpang ditolak — bukan diam-diam diabaikan.
     */
    private function validatePaymentMode(Item $item, ?string $mode): ?JsonResponse
    {
        if ($mode === null) {
            return null;
        }

        $allowed = $item->payment_mode === 'Keduanya — customer memilih'
            ? ['Full Payment', 'DP + Pelunasan']
            : [$item->payment_mode];

        if (! in_array($mode, $allowed, true)) {
            return $this->error('Metode pembayaran itu tidak tersedia untuk item ini.', 422);
        }

        return null;
    }

    /**
     * Aturan add-on sama persis dengan papan admin - satu implementasi di
     * `AddonPolicy`, bukan salinan yang bisa melonggar diam-diam.
     *
     * @param  array<int, array{addon_id: int, qty?: int}>  $lines
     */
    private function validateAddons(Item $item, array $lines): ?JsonResponse
    {
        $rejected = AddonPolicy::rejectedFor($item, $lines);

        if ($rejected !== []) {
            return $this->error(
                'Ada add-on yang tidak bisa dipesan untuk item ini: '.AddonPolicy::namesOf($rejected).'.',
                422,
            );
        }

        return null;
    }

    /**
     * Slot survey diperiksa ulang dengan `SurveySlots` yang sama seperti saat
     * daftarnya disusun — daftar yang dilihat pengunjung bisa sudah basi.
     *
     * @param  array{date?: string, session?: string}|null  $survey
     */
    private function validateSurvey(?array $survey, string $checkIn): ?JsonResponse
    {
        if (empty($survey)) {
            return null;
        }

        $bookable = SurveySlots::isBookable($survey['date'], $survey['session'], $checkIn);

        if (! $bookable) {
            return $this->error('Jadwal survey itu sudah tidak tersedia. Silakan pilih slot lain.', 422);
        }

        return null;
    }
}
