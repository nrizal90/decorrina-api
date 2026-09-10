<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Item;

/**
 * Add-on mana yang boleh dipesan bersama sebuah item.
 *
 * Aturannya: add-on harus BERSTATUS AKTIF dan BERLAKU untuk item itu - yaitu
 * tertaut langsung ke itemnya, atau ke kategorinya (berlaku untuk seluruh
 * kamar di villa tersebut).
 *
 * Tinggal di sini, bukan di controller, karena dipakai dua jalur: papan admin
 * (B3) dan pemesanan oleh pengunjung (A7). Sempat disalin ke jalur publik dan
 * salinannya lebih longgar - add-on milik villa lain lolos - jadi sekarang
 * hanya ada satu implementasi.
 */
class AddonPolicy
{
    /**
     * Id add-on yang DITOLAK dari daftar yang diminta. Kosong berarti semuanya
     * boleh.
     *
     * @param  array<int, array{addon_id: int, qty?: int}>  $lines
     * @return array<int, int>
     */
    public static function rejectedFor(Item $item, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $requested = array_unique(array_column($lines, 'addon_id'));

        $allowed = Addon::whereIn('id', $requested)
            ->where('status', 'Aktif')
            ->where(fn ($query) => $query
                ->whereHas('items', fn ($q) => $q->whereKey($item->id))
                ->orWhereHas('categories', fn ($q) => $q->whereKey($item->category_id)))
            ->pluck('id')
            ->all();

        return array_values(array_diff($requested, $allowed));
    }

    /** Nama add-on yang ditolak, untuk pesan error yang menyebutkannya. */
    public static function namesOf(array $ids): string
    {
        return Addon::whereIn('id', $ids)->pluck('name')->implode(', ');
    }
}
