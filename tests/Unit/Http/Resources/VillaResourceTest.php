<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\VillaDetailResource;
use App\Http\Resources\VillaResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Aturan "coming soon" di katalog publik A2/A3 diturunkan di resource, bukan
 * di DB — jadi diuji langsung pada objek kategori tanpa menyentuh database.
 */
class VillaResourceTest extends TestCase
{
    /** Kategori dengan agregat harga/kapasitas seperti hasil withMin/withMax. */
    private function category(array $attributes = []): Category
    {
        return (new Category)->forceFill(array_merge([
            'slug' => 'villa-asri',
            'name' => 'Villa Asri',
            'icon' => 'home',
            'tagline' => 'Sejuk di kaki gunung',
            'description' => 'Deskripsi villa.',
            'location' => 'Bandung',
            'status' => 'Aktif',
            'price_from' => '4000000',
            'cap_min' => '8',
            'cap_max' => '30',
        ], $attributes));
    }

    public function test_active_category_with_price_is_bookable(): void
    {
        $payload = (new VillaResource($this->category()))->toArray(new Request);

        $this->assertFalse($payload['coming_soon']);
        $this->assertSame(4000000, $payload['price_from']);
        $this->assertSame(['min' => 8, 'max' => 30], $payload['capacity']);
        $this->assertSame('villa-asri', $payload['slug']);
    }

    public function test_inactive_category_is_coming_soon_and_hides_price(): void
    {
        $payload = (new VillaResource($this->category(['status' => 'Nonaktif'])))->toArray(new Request);

        $this->assertTrue($payload['coming_soon']);
        $this->assertNull($payload['price_from']);
    }

    public function test_active_category_without_active_item_is_coming_soon(): void
    {
        $payload = (new VillaResource($this->category(['price_from' => null])))->toArray(new Request);

        $this->assertTrue($payload['coming_soon']);
        $this->assertNull($payload['price_from']);
    }

    public function test_null_capacity_stays_null_instead_of_zero(): void
    {
        $payload = (new VillaResource($this->category([
            'price_from' => null,
            'cap_min' => null,
            'cap_max' => null,
        ])))->toArray(new Request);

        $this->assertSame(['min' => null, 'max' => null], $payload['capacity']);
    }

    public function test_detail_resource_applies_the_same_coming_soon_rule(): void
    {
        $active = (new VillaDetailResource($this->category()))->toArray(new Request);
        $inactive = (new VillaDetailResource($this->category(['status' => 'Nonaktif'])))->toArray(new Request);

        $this->assertFalse($active['coming_soon']);
        $this->assertSame(4000000, $active['price_from']);
        $this->assertSame('Bandung', $active['location']);
        $this->assertTrue($inactive['coming_soon']);
        $this->assertNull($inactive['price_from']);
    }
}
