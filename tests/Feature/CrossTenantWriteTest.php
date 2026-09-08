<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Facility;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kebocoran TULIS lintas tenant lewat aturan `exists`.
 *
 * `exists:{tabel},id` bawaan Laravel adalah query DB mentah — ia tidak melewati
 * Eloquent, jadi Global Scope BelongsToTenant tidak berlaku. Sebelum
 * App\Rules\ExistsInTenant dipakai, seluruh skenario di berkas ini BERHASIL
 * (201/200) dan menghasilkan baris yang menunjuk induk milik tenant lain.
 *
 * Kebocorannya tak bergejala: saat dibaca, Global Scope justru menyembunyikan
 * induknya sehingga relasi terbaca null — datanya rusak diam-diam.
 */
class CrossTenantWriteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $other;

    private ?Category $foreignCategory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->other = Tenant::create([
            'name' => 'Klien Lain',
            'slug' => 'klien-lain',
            'status' => 'Aktif',
        ]);
    }

    private function actAsKlien(): User
    {
        $user = User::where('email', 'admin@decorinna.test')->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Kategori milik tenant lain — tak terlihat oleh aktor kita.
     * Di-cache karena `slug` unik per tenant: memanggilnya dua kali dalam satu
     * test akan melanggar constraint, bukan menguji apa pun.
     */
    private function foreignCategory(): Category
    {
        return $this->foreignCategory ??= Category::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $this->other->id,
            'name' => 'Villa Tetangga',
            'slug' => 'villa-tetangga',
            'status' => 'Aktif',
        ]);
    }

    private function foreignFacility(): Facility
    {
        return Facility::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $this->other->id,
            'name' => 'Fasilitas Tetangga',
            'status' => 'Aktif',
        ]);
    }

    private function foreignItem(): Item
    {
        return Item::withoutGlobalScope('tenant')->forceCreate([
            'tenant_id' => $this->other->id,
            'category_id' => $this->foreignCategory()->id,
            'name' => 'Kamar Tetangga',
            'cap_min' => 1,
            'cap_max' => 2,
            'price_weekday' => 1,
            'price_weekend' => 1,
            'payment_mode' => 'Full Payment',
            'status' => 'Aktif',
        ]);
    }

    // ------------------------------------------------------------------- item

    public function test_item_cannot_be_created_under_a_foreign_category(): void
    {
        $this->actAsKlien();

        $this->postJson('/api/admin/items', [
            'name' => 'Kamar Sisipan',
            'category_id' => $this->foreignCategory()->id,
            'cap_min' => 1,
            'cap_max' => 4,
            'price_weekday' => 1_000_000,
            'price_weekend' => 1_200_000,
            'payment_mode' => 'Full Payment',
            'status' => 'Aktif',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertDatabaseMissing('items', ['name' => 'Kamar Sisipan']);
    }

    public function test_item_cannot_be_moved_to_a_foreign_category(): void
    {
        $this->actAsKlien();
        $own = Item::where('name', 'Kamar Superior')->firstOrFail();

        $this->patchJson("/api/admin/items/{$own->id}", [
            'category_id' => $this->foreignCategory()->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(
            $own->category_id,
            $own->fresh()->category_id,
            'Kategori item tidak boleh berubah ketika validasi ditolak.',
        );
    }

    // --------------------------------------------------------------- kategori

    public function test_category_cannot_link_a_foreign_facility(): void
    {
        $this->actAsKlien();
        $foreign = $this->foreignFacility();

        $this->postJson('/api/admin/categories', [
            'name' => 'Villa Baru',
            'status' => 'Aktif',
            'facility_ids' => [$foreign->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('facility_ids.0');

        $this->assertSame(
            0,
            DB::table('category_facility')->where('facility_id', $foreign->id)->count(),
            'Pivot lintas tenant tidak boleh terbentuk.',
        );
    }

    // -------------------------------------------------------------- fasilitas

    public function test_facility_cannot_link_a_foreign_category(): void
    {
        $this->actAsKlien();

        $this->postJson('/api/admin/facilities', [
            'name' => 'Fasilitas Baru',
            'category_ids' => [$this->foreignCategory()->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_ids.0');
    }

    // ----------------------------------------------------------------- add-on

    public function test_addon_cannot_link_a_foreign_category_or_item(): void
    {
        $this->actAsKlien();

        $this->postJson('/api/admin/addons', [
            'name' => 'Add-on Nakal',
            'price' => 50_000,
            'category_ids' => [$this->foreignCategory()->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_ids.0');

        $this->postJson('/api/admin/addons', [
            'name' => 'Add-on Nakal',
            'price' => 50_000,
            'item_ids' => [$this->foreignItem()->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_ids.0');

        $this->assertDatabaseMissing('addons', ['name' => 'Add-on Nakal']);
    }

    // ------------------------------------------------------- tetap boleh jalan

    public function test_linking_within_the_same_tenant_still_works(): void
    {
        $this->actAsKlien();

        $ownFacility = Facility::where('name', 'Private Pool')->firstOrFail();
        $ownCategory = Category::where('slug', 'villa-de-corrinna')->firstOrFail();
        $ownItem = Item::where('name', 'Kamar Superior')->firstOrFail();

        // Penjagaan ini tidak boleh menghalangi pemakaian yang sah.
        $this->postJson('/api/admin/categories', [
            'name' => 'Villa Sah',
            'status' => 'Aktif',
            'facility_ids' => [$ownFacility->id],
        ])->assertCreated();

        $this->postJson('/api/admin/addons', [
            'name' => 'Add-on Sah',
            'price' => 50_000,
            'category_ids' => [$ownCategory->id],
            'item_ids' => [$ownItem->id],
        ])->assertCreated();

        $addon = Addon::where('name', 'Add-on Sah')->firstOrFail();
        $this->assertCount(1, $addon->categories);
        $this->assertCount(1, $addon->items);
    }

    // ------------------------------------------------------------ soft delete

    public function test_soft_deleted_rows_are_not_valid_targets(): void
    {
        $this->actAsKlien();

        $facility = Facility::where('name', 'Private Pool')->firstOrFail();
        $facility->delete(); // soft delete

        // `exists` bawaan tetap meloloskan baris terhapus karena hanya melihat
        // kolom id — aturan baru ikut memeriksa deleted_at.
        $this->postJson('/api/admin/categories', [
            'name' => 'Villa Fasilitas Terhapus',
            'status' => 'Aktif',
            'facility_ids' => [$facility->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('facility_ids.0');
    }
}
