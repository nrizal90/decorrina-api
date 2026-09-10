<?php

namespace App\Http\Requests\Booking;

use App\Models\Guest;
use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking yang dibuat sendiri oleh pengunjung (A6–A10).
 *
 * Beda dari `StoreBookingRequest` milik admin dalam dua hal, dan keduanya
 * disengaja:
 *
 *  1. `check_in` TIDAK boleh lampau. Kelonggaran tanggal lampau ada untuk
 *     mencatat tamu walk-in yang sudah menginap — itu pekerjaan admin, bukan
 *     sesuatu yang masuk akal dilakukan pengunjung.
 *  2. Kontak tamu wajib. Booking tanpa cara menghubungi tamunya tidak bisa
 *     ditindaklanjuti siapa pun, sementara admin masih bisa mencatat booking
 *     seadanya karena tamunya ada di depan mereka.
 */
class PublicStoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', new ExistsInTenant('items')],

            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['required', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'guest_origin' => ['nullable', 'string', 'max:255'],
            'guest_type' => ['nullable', Rule::in(Guest::TYPES)],

            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],

            'pax' => ['required', 'integer', 'min:1'],
            'vehicle_count' => ['nullable', 'integer', 'min:0', 'max:99'],

            'addons' => ['sometimes', 'array'],
            'addons.*.addon_id' => ['required', 'integer', 'distinct', new ExistsInTenant('addons')],
            'addons.*.qty' => ['sometimes', 'integer', 'min:1', 'max:99'],

            'survey' => ['nullable', 'array'],
            'survey.date' => ['required_with:survey', 'date_format:Y-m-d'],
            'survey.session' => ['required_with:survey', 'string', 'max:30'],
            'survey.notes' => ['nullable', 'string'],

            // Hanya bermakna untuk item yang menawarkan dua mode; kecocokannya
            // dengan kebijakan item diperiksa di controller.
            'payment_mode' => ['nullable', 'string', 'max:50'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_in.after_or_equal' => 'Tanggal check-in tidak boleh di masa lalu.',
            'guest_phone.required' => 'Nomor WhatsApp wajib diisi agar kami bisa mengonfirmasi booking Anda.',
        ];
    }
}
