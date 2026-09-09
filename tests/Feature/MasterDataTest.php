<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Facility;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji Master Data (Fase 2): CRUD admin, katalog publik A2/A3, isolasi
 * tenant, dan gating permission.
 */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function klien(): User
    {
        return User::where('email', 'admin@decorinna.test')->firstOrFail();
    }

    private function tenantId(): int
    {
        return Tenant::where('slug', 'decorinna')->firstOrFail()->id;
    }

    // ---------------------------------------------------------------- publik

    public function test_public_villa_listing_derives_price_and_capacity_from_active_items(): void
    {
        $response = $this->withHeader('X-Tenant', 'decorinna')->getJson('/api/villas');

        $response->assertOk();

        $villa = collect($response->json('data'))->firstWhere('slug', 'villa-de-corrinna');

        // Kamar Superior (4jt) aktif, Kamar Standard (3,2jt) nonaktif — harga
        // "Mulai dari" harus mengabaikan item nonaktif.
        $this->assertSame(4_000_000, $villa['price_from']);
        $this->assertSame(8, $villa['capacity']['min']);
        $this->assertSame(15, $villa['capacity']['max']);
        $this->assertFalse($villa['coming_soon']);
    }

    public function test_public_villa_listing_keeps_inactive_category_as_coming_soon(): void
    {
        $response = $this->withHeader('X-Tenant', 'decorinna')->getJson('/api/villas');

        $camping = collect($response->json('data'))->firstWhere('slug', 'wisata-camping');

        // Kartu tetap dikembalikan (frontend meredupkannya), bukan disaring habis.
        $this->assertNotNull($camping);
        $this->assertTrue($camping['coming_soon']);
        $this->assertNull($camping['price_from']);
    }

    public function test_public_villa_detail_returns_facilities_active_items_and_addons(): void
    {
        $response = $this->withHeader('X-Tenant', 'decorinna')->getJson('/api/villas/villa-de-corrinna');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Villa De Corrinna')
            ->assertJsonCount(6, 'data.facilities')
            // Hanya item Aktif — Kamar Standard tidak ikut.
            ->assertJsonCount(2, 'data.items')
            // Hanya add-on Aktif — Paket BBQ (Nonaktif) tidak ikut.
            ->assertJsonCount(2, 'data.addons');
    }

    // ------------------------------------------------- filter tanggal (A2)

    /**
     * Villa disaring dari katalog hanya bila SELURUH item aktifnya terisi pada
     * tanggal itu. De Corrinna punya dua item aktif, jadi memesan satu saja
     * tidak boleh menghilangkan kartunya.
     */
    public function test_listing_hides_villa_only_when_all_active_items_are_booked(): void
    {
        $villa = Category::where('slug', 'villa-de-corrinna')->firstOrFail();
        $items = $villa->activeItems()->get();

        $this->bookItem($items->first(), '2026-08-17', '2026-08-19');

        $this->assertContains('villa-de-corrinna', $this->listingSlugs(['check_in' => '2026-08-17']));

        $this->bookItem($items->last(), '2026-08-17', '2026-08-19');

        $this->assertNotContains('villa-de-corrinna', $this->listingSlugs(['check_in' => '2026-08-17']));
    }

    /**
     * Aturan bentroknya milik Booking (scope `overlapping`): check-out hari X
     * tidak menghalangi check-in hari X. Katalog harus ikut aturan yang sama,
     * bukan versinya sendiri.
     */
    public function test_listing_treats_checkout_day_as_available(): void
    {
        $villa = Category::where('slug', 'villa-de-corrinna')->firstOrFail();
        foreach ($villa->activeItems()->get() as $item) {
            $this->bookItem($item, '2026-08-15', '2026-08-17');
        }

        // Tamu sebelumnya check-out 17 Agustus — tanggal itu tetap bisa dipesan.
        $this->assertContains('villa-de-corrinna', $this->listingSlugs(['check_in' => '2026-08-17']));
        $this->assertNotContains('villa-de-corrinna', $this->listingSlugs(['check_in' => '2026-08-16']));
    }

    /** Kapasitas dan tanggal harus terpenuhi oleh SATU item yang sama. */
    public function test_capacity_and_date_filters_must_be_satisfied_by_the_same_item(): void
    {
        $villa = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        // Item terbesar dipesan; yang tersisa hanya item yang lebih kecil.
        $largest = $villa->activeItems()->orderByDesc('cap_max')->firstOrFail();
        $this->bookItem($largest, '2026-08-17', '2026-08-19');

        $slugs = $this->listingSlugs([
            'check_in' => '2026-08-17',
            'cap_min' => $largest->cap_max,
            'cap_max' => $largest->cap_max,
        ]);

        // Kamar yang muat sudah terisi, kamar yang kosong tidak muat.
        $this->assertNotContains('villa-de-corrinna', $slugs);
    }

    public function test_listing_rejects_a_malformed_date(): void
    {
        $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/villas?check_in=besok')
            ->assertStatus(422);
    }

    /** @param array<string, mixed> $query */
    private function listingSlugs(array $query = []): array
    {
        $response = $this->withHeader('X-Tenant', 'decorinna')
            ->getJson('/api/villas?'.http_build_query($query));

        $response->assertOk();

        return array_column($response->json('data'), 'slug');
    }

    private function bookItem(Item $item, string $checkIn, string $checkOut): void
    {
        Sanctum::actingAs($this->klien());

        $this->postJson('/api/bookings', [
            'item_id' => $item->id,
            'guest_name' => 'Tamu Uji',
            'guest_phone' => '081200000000',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'pax' => $item->cap_min,
        ])->assertCreated();
    }

    public function test_public_endpoints_need_no_authentication(): void
    {
        $this->getJson('/api/villas')->assertOk();
        $this->getJson('/api/villas/villa-de-corrinna')->assertOk();
    }

    public function test_public_catalog_is_isolated_per_tenant(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        // forceCreate: `tenant_id` sengaja tidak $fillable agar tak bisa
        // disuntik lewat body request, jadi test menembusnya secara eksplisit.
        Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Villa Tetangga',
            'slug' => 'villa-tetangga',
            'status' => 'Aktif',
        ]);

        $slugs = collect($this->withHeader('X-Tenant', 'decorinna')->getJson('/api/villas')->json('data'))
            ->pluck('slug');

        $this->assertNotContains('villa-tetangga', $slugs);
        $this->assertContains('villa-de-corrinna', $slugs);

        // Dan sebaliknya, tenant lain hanya melihat miliknya sendiri.
        $otherSlugs = collect($this->withHeader('X-Tenant', 'klien-lain')->getJson('/api/villas')->json('data'))
            ->pluck('slug');

        $this->assertSame(['villa-tetangga'], $otherSlugs->all());
    }

    public function test_unknown_tenant_slug_is_rejected(): void
    {
        $this->withHeader('X-Tenant', 'tidak-ada')->getJson('/api/villas')->assertNotFound();
    }

    // ----------------------------------------------------------------- admin

    public function test_category_list_includes_item_count(): void
    {
        Sanctum::actingAs($this->klien());

        $response = $this->getJson('/api/admin/categories');

        $response->assertOk();

        $villa = collect($response->json('data'))->firstWhere('slug', 'villa-de-corrinna');
        $this->assertSame(3, $villa['items_count']);
    }

    public function test_item_without_status_is_saved_as_draft(): void
    {
        Sanctum::actingAs($this->klien());
        $category = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        $response = $this->postJson('/api/admin/items', [
            'category_id' => $category->id,
            'name' => 'Kamar Draft',
            'cap_min' => 4,
            'cap_max' => 6,
            'price_weekday' => 1_000_000,
            'price_weekend' => 1_200_000,
            'payment_mode' => 'Full Payment',
        ]);

        // Tombol "Simpan Draft" tidak mengirim status → Nonaktif.
        $response->assertCreated()->assertJsonPath('data.status', 'Nonaktif');
    }

    public function test_item_with_dp_payment_requires_dp_minimum(): void
    {
        Sanctum::actingAs($this->klien());
        $category = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        $this->postJson('/api/admin/items', [
            'category_id' => $category->id,
            'name' => 'Kamar DP',
            'cap_min' => 4,
            'cap_max' => 6,
            'price_weekday' => 1_000_000,
            'price_weekend' => 1_200_000,
            'payment_mode' => 'DP + Pelunasan',
        ])->assertStatus(422)->assertJsonValidationErrors('dp_minimum');
    }

    public function test_item_rejects_capacity_max_below_min(): void
    {
        Sanctum::actingAs($this->klien());
        $category = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        $this->postJson('/api/admin/items', [
            'category_id' => $category->id,
            'name' => 'Kamar Salah',
            'cap_min' => 10,
            'cap_max' => 4,
            'price_weekday' => 1_000_000,
            'price_weekend' => 1_200_000,
            'payment_mode' => 'Full Payment',
        ])->assertStatus(422)->assertJsonValidationErrors('cap_max');
    }

    public function test_created_item_is_scoped_to_actor_tenant(): void
    {
        Sanctum::actingAs($this->klien());
        $category = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        $this->postJson('/api/admin/items', [
            'category_id' => $category->id,
            'name' => 'Kamar Tenant',
            'cap_min' => 2,
            'cap_max' => 4,
            'price_weekday' => 500_000,
            'price_weekend' => 600_000,
            'payment_mode' => 'Full Payment',
        ])->assertCreated();

        // tenant_id diisi otomatis oleh BelongsToTenant, bukan dari body request.
        $item = Item::withoutGlobalScope('tenant')->where('name', 'Kamar Tenant')->firstOrFail();
        $this->assertSame($this->tenantId(), $item->tenant_id);
    }

    public function test_category_slug_is_generated_and_unique(): void
    {
        Sanctum::actingAs($this->klien());

        $this->postJson('/api/admin/categories', ['name' => 'Villa De Corrinna'])
            ->assertCreated()
            // Slug dasar sudah dipakai seeder → diberi sufiks.
            ->assertJsonPath('data.slug', 'villa-de-corrinna-2');
    }

    public function test_category_with_items_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->klien());
        $category = Category::where('slug', 'villa-de-corrinna')->firstOrFail();

        $this->deleteJson("/api/admin/categories/{$category->id}")->assertStatus(422);
    }

    public function test_addon_links_are_returned_flat_for_the_table(): void
    {
        Sanctum::actingAs($this->klien());

        $response = $this->getJson('/api/admin/addons');

        $extraBed = collect($response->json('data'))->firstWhere('name', 'Extra Bed');

        $this->assertCount(2, $extraBed['links']);
        $this->assertSame('category', $extraBed['links'][0]['type']);
        $this->assertContains(
            'Villa De Corrinna',
            array_column($extraBed['links'], 'label'),
        );
    }

    public function test_facilities_are_returned_without_pagination(): void
    {
        Sanctum::actingAs($this->klien());

        $response = $this->getJson('/api/admin/facilities');

        // Layar B4 menampilkan semua chip sekaligus — tak boleh ada meta paginasi.
        $response->assertOk()->assertJsonCount(6, 'data')->assertJsonMissingPath('meta');
    }

    public function test_facility_list_reports_how_many_villas_use_it(): void
    {
        Sanctum::actingAs($this->klien());

        $facilities = collect($this->getJson('/api/admin/facilities')->json('data'));

        // Dipakai layar admin untuk memperingatkan dampak sebelum menghapus:
        // menghapus fasilitas melepasnya dari setiap villa yang memakainya.
        $pool = $facilities->firstWhere('name', 'Private Pool');

        $this->assertNotNull($pool);
        $this->assertSame(
            Facility::where('name', 'Private Pool')->firstOrFail()->categories()->count(),
            $pool['categories_count'],
        );
    }

    public function test_admin_cannot_see_master_data_of_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Klien Lain', 'slug' => 'klien-lain', 'status' => 'Aktif']);
        Facility::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $other->id,
            'name' => 'Fasilitas Tetangga',
            'status' => 'Aktif',
        ]);

        Sanctum::actingAs($this->klien());

        $names = collect($this->getJson('/api/admin/facilities')->json('data'))->pluck('name');

        $this->assertNotContains('Fasilitas Tetangga', $names);
    }

    // ------------------------------------------------------------ permission

    public function test_customer_cannot_touch_master_data(): void
    {
        $customer = User::factory()->create(['tenant_id' => null]);
        $customer->assignRole('customer');
        Sanctum::actingAs($customer);

        $this->getJson('/api/admin/items')->assertForbidden();
        $this->getJson('/api/admin/categories')->assertForbidden();
        $this->postJson('/api/admin/facilities', ['name' => 'Nekat'])->assertForbidden();
    }

    public function test_stakeholder_can_read_but_not_write_master_data(): void
    {
        $stakeholder = User::factory()->create(['tenant_id' => $this->tenantId()]);
        $stakeholder->assignRole('stakeholder');
        Sanctum::actingAs($stakeholder);

        // Stakeholder read-only: punya :index/:show, tidak punya :store.
        $this->getJson('/api/admin/items')->assertOk();
        $this->postJson('/api/admin/items', [
            'category_id' => 1,
            'name' => 'Nekat',
            'cap_min' => 1,
            'cap_max' => 2,
            'price_weekday' => 1,
            'price_weekend' => 1,
            'payment_mode' => 'Full Payment',
        ])->assertForbidden();
    }
}
