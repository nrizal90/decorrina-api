<?php

namespace App\Http\Requests\Ledger;

use App\Models\LedgerEntry;
use App\Rules\ExistsInTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Modal "Catat Transaksi" (B9) — entri manual, pemasukan maupun pengeluaran. */
class StoreLedgerEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LedgerEntry::TYPES)],
            // String bebas — daftar di config/finance.php hanya saran.
            'category' => ['required', 'string', 'max:100'],
            // Integer rupiah positif; arahnya dari `type`, bukan dari tanda.
            'amount' => ['required', 'integer', 'min:1'],
            'booking_id' => ['nullable', 'integer', new ExistsInTenant('bookings')],
            'notes' => ['nullable', 'string'],
        ];
    }
}
