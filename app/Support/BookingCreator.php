<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Item;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pembuatan booking — SATU implementasi untuk dua jalur.
 *
 * Papan admin (B3) dan alur customer (A6–A10) menghasilkan booking yang sama
 * persis: snapshot harga, kode booking, baris add-on, dan jadwal survey.
 * Yang berbeda hanya SIAPA yang membuatnya (`source`) dan aturan validasi di
 * depannya — mis. tanggal lampau boleh untuk pencatatan walk-in, tidak untuk
 * pengunjung yang memesan sendiri.
 *
 * Dipisahkan ke sini supaya perbedaan itu tetap tinggal di controller
 * masing-masing, sementara isi booking-nya tidak pernah bercabang dua.
 */
class BookingCreator
{
    /**
     * @param  array{
     *     guest_name: string,
     *     guest_phone?: ?string,
     *     guest_email?: ?string,
     *     guest_birth_date?: ?string,
     *     guest_origin?: ?string,
     *     guest_type?: ?string,
     *     check_in: string,
     *     check_out: string,
     *     pax: int,
     *     vehicle_count?: ?int,
     *     payment_mode?: ?string,
     *     addons?: array<int, array{addon_id: int, qty?: int}>,
     *     survey?: ?array{date: string, session: string, notes?: ?string},
     *     notes?: ?string,
     * }  $data
     */
    public static function create(Item $item, array $data, string $source, ?User $user = null): Booking
    {
        return DB::transaction(function () use ($item, $data, $source, $user) {
            $checkIn = $data['check_in'];
            $checkOut = $data['check_out'];

            $stay = BookingPricing::forStay($item, $checkIn, $checkOut);

            $addonLines = $data['addons'] ?? [];
            $addons = Addon::whereIn('id', array_column($addonLines, 'addon_id'))->get()->keyBy('id');
            $addonPricing = BookingPricing::forAddons($addonLines, $addons);

            $guest = self::resolveGuest($data, $user);

            $booking = Booking::create([
                'item_id' => $item->id,
                'guest_id' => $guest->id,
                'kode_booking' => BookingCode::generate(app('currentTenantId')),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $stay['nights'],
                'pax' => $data['pax'],
                'vehicle_count' => $data['vehicle_count'] ?? null,
                // Snapshot harga & kebijakan pembayaran item saat ini.
                'price_weekday' => $item->price_weekday,
                'price_weekend' => $item->price_weekend,
                'subtotal_item' => $stay['subtotal'],
                'subtotal_addons' => $addonPricing['subtotal'],
                'total' => $stay['subtotal'] + $addonPricing['subtotal'],
                // Item yang menawarkan dua mode menyerahkan pilihannya ke
                // pemesan; selain itu kebijakan item yang berlaku.
                'payment_mode' => $data['payment_mode'] ?? $item->payment_mode,
                'dp_minimum' => $item->dp_minimum,
                // Selalu lahir menunggu pembayaran — pembayaran nyata (Fase 4)
                // maupun admin yang menandai manual sama-sama bergerak dari
                // sini lewat state machine, bukan melompatinya.
                'status' => Booking::STATUS_MENUNGGU,
                'source' => $source,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['survey'])) {
                self::createSurvey($booking, $item, $guest, $checkIn, $data['survey']);
            }

            foreach ($addonPricing['lines'] as $line) {
                $booking->addons()->create($line);
            }

            return $booking;
        });
    }

    /**
     * Tamu dikenali dari NOMOR TELEPON. Data yang sudah ada tidak ditimpa —
     * tamu yang kembali memesan tidak boleh kehilangan profil lamanya hanya
     * karena form kali ini dibiarkan kosong.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveGuest(array $data, ?User $user): Guest
    {
        $phone = $data['guest_phone'] ?? null;

        $profile = array_filter([
            'email' => $data['guest_email'] ?? null,
            'birth_date' => $data['guest_birth_date'] ?? null,
            'origin' => $data['guest_origin'] ?? null,
            'guest_type' => $data['guest_type'] ?? null,
        ]);

        if ($phone) {
            $existing = Guest::where('phone', $phone)->first();

            if ($existing) {
                $existing->fill(array_filter([
                    'name' => $existing->name ?: $data['guest_name'],
                    'email' => $existing->email ?: ($profile['email'] ?? null),
                    'birth_date' => $existing->birth_date ?: ($profile['birth_date'] ?? null),
                    'origin' => $existing->origin ?: ($profile['origin'] ?? null),
                    'guest_type' => $existing->guest_type ?: ($profile['guest_type'] ?? null),
                    // Tamu yang tadinya anonim lalu memesan sambil login:
                    // akunnya ditautkan, tapi tautan lama tidak dipindah.
                    'user_id' => $existing->user_id ?: $user?->id,
                ]))->save();

                return $existing;
            }
        }

        return Guest::create($profile + [
            'name' => $data['guest_name'],
            'phone' => $phone,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * @param  array{date: string, session: string, notes?: ?string}  $survey
     */
    private static function createSurvey(
        Booking $booking,
        Item $item,
        Guest $guest,
        string $checkIn,
        array $survey,
    ): void {
        Survey::create([
            // Villa diturunkan dari item — papan B4 menampilkan kolom villa,
            // dan jalur customer selalu tahu kamarnya.
            'category_id' => $item->category_id,
            'item_id' => $item->id,
            'booking_id' => $booking->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'planned_check_in' => $checkIn,
            'scheduled_date' => $survey['date'],
            // Jam disimpan juga, bukan hanya kode sesi: papan admin menampilkan
            // "13 Agu 2026, 10:00" untuk survey dari jalur mana pun.
            'scheduled_time' => SurveySlots::startTimeOf($survey['session']),
            'session' => $survey['session'],
            'status' => Survey::STATUS_TERJADWAL,
            'notes' => $survey['notes'] ?? null,
        ]);
    }
}
