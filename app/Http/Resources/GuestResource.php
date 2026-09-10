<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tabel Profil Tamu (B8) sekaligus isi modal detailnya.
 *
 * Agregat (`bookings_count`, `total_spent`, ...) datang dari controller lewat
 * withCount/withSum — dihitung di query, bukan dengan memuat seluruh booking
 * tiap tamu hanya untuk menjumlahkannya.
 *
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'birth_date' => $this->birth_date?->toDateString(),
            'origin' => $this->origin,
            'guest_type' => $this->guest_type,

            // "Anonim" di layar = memesan tanpa akun. Namanya tetap ada (form
            // mewajibkannya); yang tidak ada hanyalah tautan ke `users`.
            'has_account' => $this->user_id !== null,

            'bookings_count' => (int) ($this->bookings_count ?? 0),
            // Booking yang dibatalkan tidak dihitung sebagai pengeluaran.
            'total_spent' => (int) ($this->total_spent ?? 0),

            // Angka "jumlah tamu / kendaraan" di profil adalah milik booking
            // TERAKHIR — tamu yang sama bisa datang dengan rombongan berbeda.
            'latest_pax' => $this->whenLoaded('latestBooking', fn () => $this->latestBooking?->pax),
            'latest_vehicle_count' => $this->whenLoaded('latestBooking', fn () => $this->latestBooking?->vehicle_count),

            'bookings' => $this->whenLoaded('bookings', fn () => $this->bookings->map(fn (Booking $b) => [
                'id' => $b->id,
                'kode_booking' => $b->kode_booking,
                'villa' => $b->item?->category?->name,
                'item' => $b->item?->name,
                'check_in' => $b->check_in?->toDateString(),
                'check_out' => $b->check_out?->toDateString(),
                'nights' => $b->nights,
                'pax' => $b->pax,
                'status' => $b->status,
                'total' => $b->total,
            ])->values()),

            'created_at' => $this->created_at,
        ];
    }
}
