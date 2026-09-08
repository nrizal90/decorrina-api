<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Facility;
use App\Models\Item;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Data awal Master Data (Fase 2), disalin dari frontend agar layar B4 & katalog
 * publik langsung terisi data yang realistis:
 *  - kategori & status  → CategoryList.tsx + VillaListing.tsx
 *  - item               → seed-data.ts (`seedItems`)
 *  - fasilitas          → FacilityMaster.tsx (`initialFacilities`)
 *  - add-on             → AddonMaster.tsx (`initialAddons`) + satuan dari A7
 *
 * Idempoten — aman dijalankan berulang.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'decorinna')->first();

        if (! $tenant) {
            $this->command?->warn('Tenant "decorinna" belum ada — jalankan AuthRolePermissionSeeder lebih dulu.');

            return;
        }

        $categories = $this->seedCategories($tenant->id);
        $this->seedItems($tenant->id, $categories);
        $facilities = $this->seedFacilities($tenant->id);
        $this->seedAddons($tenant->id, $categories);

        // Fasilitas kawasan dipakai bersama oleh kedua villa yang sudah jalan.
        foreach (['villa-de-corrinna', 'villa-cendana-wangi'] as $slug) {
            $categories[$slug]->facilities()->syncWithoutDetaching($facilities->pluck('id'));
        }
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(int $tenantId): array
    {
        $rows = [
            [
                'slug' => 'villa-de-corrinna',
                'name' => 'Villa De Corrinna',
                'icon' => 'house',
                'status' => 'Aktif',
                'tagline' => 'Rumah utama dengan taman luas dan kolam privat, dikelilingi pohon pinus.',
                'location' => 'Kawasan Gerbang Gunungsari, Pamijahan, Bogor',
                'description' => 'Villa De Corrinna adalah rumah utama kami — sanctuary yang tenang di antara pepohonan pinus Pamijahan. Dengan taman luas dan kolam renang privat, villa ini dirancang untuk keluarga besar atau rombongan yang ingin berkumpul tanpa terburu-buru.',
            ],
            [
                'slug' => 'villa-cendana-wangi',
                'name' => 'Villa Cendana Wangi',
                'icon' => 'house',
                'status' => 'Aktif',
                'tagline' => 'Suasana lebih intim dengan pemandangan lembah dan udara pegunungan.',
                'location' => 'Kawasan Gerbang Gunungsari, Pamijahan, Bogor',
                'description' => null,
            ],
            [
                // Nonaktif → tampil sebagai "Segera hadir" di katalog publik.
                'slug' => 'wisata-camping',
                'name' => 'Wisata Camping',
                'icon' => 'tent',
                'status' => 'Nonaktif',
                'tagline' => 'Pengalaman berkemah di tepi hutan pinus — sedang dipersiapkan untuk Anda.',
                'location' => 'Kawasan Gerbang Gunungsari, Pamijahan, Bogor',
                'description' => null,
            ],
        ];

        $categories = [];

        foreach ($rows as $row) {
            $categories[$row['slug']] = Category::withoutGlobalScope('tenant')->updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $row['slug']],
                $row + ['tenant_id' => $tenantId],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedItems(int $tenantId, array $categories): void
    {
        $villa = $categories['villa-de-corrinna'];

        $rows = [
            [
                'name' => 'Kamar Superior',
                'cap_min' => 8, 'cap_max' => 12,
                'price_weekday' => 4_000_000, 'price_weekend' => 4_800_000,
                'status' => 'Aktif',
                'payment_mode' => 'Full Payment',
                'dp_minimum' => null,
                'requires_survey' => false,
            ],
            [
                'name' => 'Kamar Deluxe',
                'cap_min' => 10, 'cap_max' => 15,
                'price_weekday' => 4_500_000, 'price_weekend' => 5_200_000,
                'status' => 'Aktif',
                'payment_mode' => 'DP + Pelunasan',
                // ⚠ Placeholder Rp 2.000.000 — kebijakan DP belum final (dok 05).
                'dp_minimum' => 2_000_000,
                'requires_survey' => true,
            ],
            [
                'name' => 'Kamar Standard',
                'cap_min' => 6, 'cap_max' => 10,
                'price_weekday' => 3_200_000, 'price_weekend' => 3_800_000,
                'status' => 'Nonaktif',
                'payment_mode' => 'Full Payment',
                'dp_minimum' => null,
                'requires_survey' => false,
            ],
        ];

        foreach ($rows as $row) {
            Item::withoutGlobalScope('tenant')->updateOrCreate(
                ['tenant_id' => $tenantId, 'category_id' => $villa->id, 'name' => $row['name']],
                $row + ['tenant_id' => $tenantId, 'category_id' => $villa->id],
            );
        }
    }

    /**
     * @return Collection<int, Facility>
     */
    private function seedFacilities(int $tenantId)
    {
        $rows = [
            ['name' => 'Private Pool', 'icon' => 'waves'],
            ['name' => 'Lapangan Basket', 'icon' => 'circle-dot'],
            ['name' => 'Karaoke', 'icon' => 'mic-vocal'],
            ['name' => 'Genset', 'icon' => 'zap'],
            ['name' => 'Mushala', 'icon' => 'moon-star'],
            ['name' => 'Rooftop', 'icon' => 'building-2'],
        ];

        return collect($rows)->map(fn (array $row) => Facility::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $tenantId, 'name' => $row['name']],
            $row + ['tenant_id' => $tenantId, 'status' => 'Aktif'],
        ));
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedAddons(int $tenantId, array $categories): void
    {
        $rows = [
            [
                'name' => 'Extra Bed',
                'price' => 150_000,
                'unit' => 'unit',
                'status' => 'Aktif',
                'description' => 'Kasur tambahan untuk kamar yang sudah dipilih.',
                'links' => ['villa-de-corrinna', 'villa-cendana-wangi'],
            ],
            [
                'name' => 'Late Checkout',
                'price' => 150_000,
                'unit' => 'jam',
                'status' => 'Aktif',
                'description' => 'Perpanjangan waktu checkout per jam.',
                'links' => ['villa-de-corrinna', 'villa-cendana-wangi'],
            ],
            [
                'name' => 'Paket BBQ',
                'price' => 500_000,
                'unit' => null, // harga flat
                'status' => 'Nonaktif',
                'description' => 'Peralatan dan bahan BBQ untuk malam santai bersama.',
                'links' => ['villa-de-corrinna'],
                // Deadline catering H-4 (kebijakan tetap, tampil di ItemForm).
                'deadline_days' => 4,
            ],
        ];

        foreach ($rows as $row) {
            $links = $row['links'];
            unset($row['links']);

            $addon = Addon::withoutGlobalScope('tenant')->updateOrCreate(
                ['tenant_id' => $tenantId, 'name' => $row['name']],
                $row + ['tenant_id' => $tenantId],
            );

            $addon->categories()->syncWithoutDetaching(
                collect($links)->map(fn (string $slug) => $categories[$slug]->id)->all(),
            );
        }
    }
}
