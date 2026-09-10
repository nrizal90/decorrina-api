<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Category;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Buku kas (B9, Fase 7): pencatatan otomatis dari booking dan agregatnya.
 *
 * Aturan pencatatan otomatis, mengikuti state machine booking:
 *  - DP Dibayar  -> pemasukan sebesar `dp_minimum` booking (snapshot dari item).
 *  - Lunas       -> pemasukan sebesar SISA: total dikurangi yang sudah tercatat
 *                   untuk booking itu. Booking yang lompat langsung ke Lunas
 *                   tercatat penuh; yang lewat DP dulu hanya tercatat sisanya.
 *  - Dibatalkan  -> TIDAK ada pembalikan otomatis. Kebijakan refund belum
 *                   diputuskan (docs/05), jadi uang yang sudah masuk tetap
 *                   tercatat masuk; pengembalian dicatat manual sebagai
 *                   pengeluaran bila memang terjadi.
 *
 * Nominal DP yang sebenarnya diterima bisa berbeda dari `dp_minimum`. Tidak
 * ada endpoint ubah/hapus (seeder hanya punya ledger:index & ledger:store):
 * selisihnya dicatat sebagai entri manual tambahan, seperti buku kas sungguhan
 * yang tidak menghapus baris melainkan mengoreksinya.
 */
class Ledger
{
    public const CATEGORY_VILLA = 'Booking Villa';

    public const CATEGORY_ADDON = 'Add-on';

    /**
     * Catat pemasukan untuk perubahan status. Idempoten terhadap total:
     * tidak pernah mencatat lebih dari `total` booking, berapa kali pun
     * dipanggil.
     *
     * @return Collection<int, LedgerEntry>
     */
    public static function recordForStatus(Booking $booking, string $newStatus): Collection
    {
        $entries = collect();

        $alreadyRecorded = (int) LedgerEntry::query()
            ->income()
            ->where('booking_id', $booking->id)
            ->sum('amount');

        $remaining = max(0, $booking->total - $alreadyRecorded);

        if ($remaining === 0) {
            return $entries;
        }

        if ($newStatus === Booking::STATUS_DP) {
            $dp = min((int) ($booking->dp_minimum ?? 0), $remaining);

            if ($dp > 0) {
                $entries->push(self::income($booking, self::CATEGORY_VILLA, $dp, "Pembayaran DP booking {$booking->kode_booking}"));
            }

            return $entries;
        }

        if ($newStatus === Booking::STATUS_LUNAS) {
            // Add-on dicatat terpisah supaya rincian per kategori di laporan
            // bulanan tidak menyatukan keduanya. Sisa setelah add-on = villa.
            $addonPart = min((int) $booking->subtotal_addons, $remaining);
            $villaPart = $remaining - $addonPart;

            if ($villaPart > 0) {
                $entries->push(self::income($booking, self::CATEGORY_VILLA, $villaPart, "Pelunasan booking {$booking->kode_booking}"));
            }
            if ($addonPart > 0) {
                $entries->push(self::income($booking, self::CATEGORY_ADDON, $addonPart, "Add-on booking {$booking->kode_booking}"));
            }
        }

        return $entries;
    }

    private static function income(Booking $booking, string $category, int $amount, string $description): LedgerEntry
    {
        return LedgerEntry::create([
            'entry_date' => Carbon::today()->toDateString(),
            'description' => $description,
            'type' => LedgerEntry::TYPE_PEMASUKAN,
            'category' => $category,
            'amount' => $amount,
            'booking_id' => $booking->id,
            'created_by' => null,
        ]);
    }

    /**
     * Ringkasan satu bulan: pendapatan, pengeluaran, dan rincian per kategori.
     *
     * @return array<string, mixed>
     */
    public static function monthly(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $rows = LedgerEntry::query()
            ->between($from, $to)
            ->selectRaw('type, category, SUM(amount) as amount')
            ->groupBy('type', 'category')
            ->orderBy('type')
            ->orderByDesc('amount')
            ->get();

        $income = (int) $rows->where('type', LedgerEntry::TYPE_PEMASUKAN)->sum('amount');
        $expense = (int) $rows->where('type', LedgerEntry::TYPE_PENGELUARAN)->sum('amount');

        return [
            'month' => $month->format('Y-m'),
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'income_by_category' => $rows->where('type', LedgerEntry::TYPE_PEMASUKAN)
                ->map(fn ($r) => ['category' => $r->category, 'amount' => (int) $r->amount])->values(),
            'expense_by_category' => $rows->where('type', LedgerEntry::TYPE_PENGELUARAN)
                ->map(fn ($r) => ['category' => $r->category, 'amount' => (int) $r->amount])->values(),
        ];
    }

    /**
     * Bulan-bulan yang punya entri, terbaru dulu — untuk dropdown pemilih bulan.
     *
     * @return array<int, string>
     */
    public static function monthsWithEntries(): array
    {
        return LedgerEntry::query()
            ->selectRaw("to_char(entry_date, 'YYYY-MM') as month")
            ->distinct()
            ->orderByDesc('month')
            ->pluck('month')
            ->all();
    }

    /**
     * Bagi hasil per bulan untuk villa yang punya skema di config/finance.php.
     *
     * Dasarnya PEMASUKAN YANG TERCATAT dari booking villa itu (baris ber-
     * `booking_id`), bukan nilai booking — bagi hasil dihitung atas uang yang
     * benar-benar masuk.
     *
     * @return array<string, mixed>|null null bila villa tidak punya skema
     */
    public static function profitShare(string $categorySlug): ?array
    {
        $scheme = config("finance.profit_share.{$categorySlug}");

        if ($scheme === null) {
            return null;
        }

        $category = Category::where('slug', $categorySlug)->first();

        if ($category === null) {
            return null;
        }

        $rows = LedgerEntry::query()
            ->income()
            ->whereHas('booking.item', fn ($q) => $q->where('category_id', $category->id))
            ->selectRaw("to_char(entry_date, 'YYYY-MM') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderByDesc('month')
            ->get()
            ->map(function ($r) use ($scheme) {
                $total = (int) $r->total;
                $owner = (int) round($total * $scheme['owner_pct'] / 100);

                return [
                    'month' => $r->month,
                    'total' => $total,
                    'owner_share' => $owner,
                    // Sisa, bukan pembulatan kedua: keduanya selalu tepat berjumlah total.
                    'operator_share' => $total - $owner,
                ];
            })
            ->values();

        return [
            'villa' => $category->name,
            'slug' => $categorySlug,
            'owner_pct' => $scheme['owner_pct'],
            'operator_pct' => $scheme['operator_pct'],
            'owner_label' => $scheme['owner_label'],
            'operator_label' => $scheme['operator_label'],
            'rows' => $rows,
        ];
    }
}
