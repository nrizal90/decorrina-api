<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Bentuk booking untuk jalur PUBLIK (`/public/bookings`, lookup).
 *
 * Endpoint itu tanpa auth dan mengenali tamu dari nomor telepon saja, jadi
 * siapa pun yang tahu nomor korban bisa "memesan" atas namanya. Karena itu
 * profil tamu yang tersimpan (email, tanggal lahir, asal) TIDAK boleh ikut
 * kembali — hanya yang memang dikirim pemesan (audit 2026-09-11 T-03).
 * Field internal papan admin (transisi status) juga tidak relevan di sini.
 */
class PublicBookingResource extends BookingResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['guest'] = [
            'name' => $this->guest?->name,
            'phone' => $this->guest?->phone,
        ];
        unset($data['allowed_transitions']);

        return $data;
    }
}
