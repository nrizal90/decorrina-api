<?php

namespace App\Http\Resources;

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tabel Manajemen Survey (B4) sekaligus isi modal detailnya.
 *
 * `villa` dan `pic` diratakan seperti pada BookingResource — tabelnya hanya
 * butuh nama, bukan seluruh objek relasi.
 *
 * @mixin Survey
 */
class SurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'guest_name' => $this->guest?->name ?? $this->guest_name,
            'guest_id' => $this->guest_id,

            'villa' => $this->category?->name,
            'category_id' => $this->category_id,
            'item' => $this->item?->name,
            'item_id' => $this->item_id,

            'booking_id' => $this->booking_id,
            'kode_booking' => $this->booking?->kode_booking,

            'planned_check_in' => $this->planned_check_in?->toDateString(),
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            // Jam dipangkas ke HH:MM: kolom `time` di Postgres mengembalikan
            // detik, sementara layar dan input <time> memakai menit.
            'scheduled_time' => $this->scheduled_time === null
                ? null
                : substr((string) $this->scheduled_time, 0, 5),
            'session' => $this->session,

            'pic' => $this->pic === null
                ? null
                : ['id' => $this->pic->id, 'name' => $this->pic->name],

            'status' => $this->status,
            'report' => $this->report,
            'notes' => $this->notes,

            'created_at' => $this->created_at,
        ];
    }
}
