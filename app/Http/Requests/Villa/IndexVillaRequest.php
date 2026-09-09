<?php

namespace App\Http\Requests\Villa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Filter katalog publik A2. Semuanya opsional — tanpa parameter, endpoint
 * mengembalikan seluruh villa.
 *
 * Divalidasi meski publik: `check_in` masuk langsung ke perbandingan tanggal
 * di query, jadi string sembarang harus ditolak sebagai 422, bukan diteruskan
 * ke database.
 */
class IndexVillaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cap_min' => ['nullable', 'integer', 'min:1'],
            'cap_max' => ['nullable', 'integer', 'min:1', 'gte:cap_min'],

            // Katalog hanya menanyakan "malam ini kosong tidak?", jadi
            // check_out boleh tidak dikirim — lihat checkOut() di bawah.
            'check_in' => ['nullable', 'date_format:Y-m-d'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in'],
        ];
    }

    public function hasDateFilter(): bool
    {
        return $this->filled('check_in');
    }

    /**
     * Check-out efektif: satu malam sesudah check-in bila tidak dikirim.
     *
     * Layar listing hanya punya satu kolom tanggal, dan yang ditanyakan
     * pengunjung adalah "villa mana yang bebas mulai tanggal ini" — satu malam
     * sudah cukup untuk menjawabnya.
     */
    public function checkOut(): string
    {
        return $this->filled('check_out')
            ? $this->string('check_out')->toString()
            : Carbon::parse($this->string('check_in')->toString())->addDay()->toDateString();
    }
}
