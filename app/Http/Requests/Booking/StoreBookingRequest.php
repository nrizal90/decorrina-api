<?php

namespace App\Http\Requests\Booking;

use App\Models\Guest;
use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking manual oleh admin (B3). Otorisasi ditangani middleware `rbac`
 * (bookings:store).
 *
 * Metode pembayaran TIDAK diminta di sini: keputusan 2026-09-08 menetapkan
 * mode mengikuti pengaturan item, jadi controller menyalinnya dari item.
 */
class StoreBookingRequest extends FormRequest
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
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],

            // Profil tamu dari form A6. Semuanya opsional: booking manual B3
            // hanya mengisi nama & telepon, dan tamu walk-in yang dicatat
            // susulan sering tidak punya data selengkap ini.
            'guest_birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'guest_origin' => ['nullable', 'string', 'max:255'],
            'guest_type' => ['nullable', Rule::in(Guest::TYPES)],

            // SENGAJA tanpa `after_or_equal:today`. Booking manual juga dipakai
            // mencatat tamu walk-in yang sudah terlanjur menginap, jadi tanggal
            // lampau harus tetap boleh. Jangan tambahkan aturan itu tanpa
            // menyediakan jalur pencatatan susulan lebih dulu.
            'check_in' => ['required', 'date_format:Y-m-d'],
            // after: menginap minimal satu malam — check-out tak boleh sama
            // dengan check-in.
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],

            'pax' => ['required', 'integer', 'min:1'],
            // Milik satu kali menginap, bukan milik tamunya: rombongan yang
            // sama bisa datang dengan jumlah kendaraan berbeda tiap kunjungan.
            'vehicle_count' => ['nullable', 'integer', 'min:0', 'max:99'],

            'addons' => ['sometimes', 'array'],
            // `distinct`: satu add-on hanya boleh muncul sekali. Tanpa ini,
            // unique(booking_id, addon_id) di DB akan meledak jadi error 500
            // alih-alih pesan validasi. Jumlah diatur lewat `qty`.
            'addons.*.addon_id' => ['required', 'integer', 'distinct', new ExistsInTenant('addons')],
            'addons.*.qty' => ['sometimes', 'integer', 'min:1', 'max:99'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.after' => 'Tanggal check-out harus setelah tanggal check-in (menginap minimal satu malam).',
            'pax.min' => 'Jumlah tamu minimal 1 orang.',
            'addons.*.addon_id.distinct' => 'Add-on yang sama dikirim lebih dari sekali. Pakai jumlah (qty), bukan baris ganda.',
        ];
    }
}
