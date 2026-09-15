<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use Database\Seeders\AuthRolePermissionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Upload/urut/hapus foto item (B4). Disk dipalsukan — tidak menulis ke storage/. */
class ItemPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthRolePermissionSeeder::class);
        $this->seed(MasterDataSeeder::class);
        Storage::fake('public');
        Sanctum::actingAs(User::where('email', 'admin@decorinna.test')->firstOrFail());
    }

    private function item(): Item
    {
        return Item::where('name', 'Kamar Superior')->firstOrFail();
    }

    private function otherItem(Item $item): Item
    {
        return Item::where('id', '!=', $item->id)->firstOrFail();
    }

    private function upload(Item $item, string $name = 'a.jpg')
    {
        return $this->post("/api/admin/items/{$item->id}/photos", [
            'photo' => UploadedFile::fake()->image($name, 800, 600),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_stores_file_and_first_photo_becomes_cover(): void
    {
        $item = $this->item();

        $this->upload($item)->assertCreated()->assertJsonPath('data.is_cover', true);
        $this->upload($item, 'b.png')->assertCreated()->assertJsonPath('data.is_cover', false);

        $photos = $item->photos()->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $p) {
            Storage::disk('public')->assertExists($p->path);
            $this->assertStringStartsWith("items/{$item->id}/", $p->path);
        }

        $this->getJson("/api/admin/items/{$item->id}")->assertJsonCount(2, 'data.photos');
    }

    public function test_rejects_non_image_and_oversized_files(): void
    {
        $item = $this->item();

        $this->post("/api/admin/items/{$item->id}/photos", [
            'photo' => UploadedFile::fake()->create('x.php', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->post("/api/admin/items/{$item->id}/photos", [
            'photo' => UploadedFile::fake()->image('big.jpg')->size(6000),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_photo_quota_per_item(): void
    {
        config(['booking.max_photos_per_item' => 1]);
        $item = $this->item();

        $this->upload($item)->assertCreated();
        $this->upload($item, 'b.jpg')->assertUnprocessable();
    }

    public function test_reorder_and_cover(): void
    {
        $item = $this->item();
        $a = $this->upload($item)->json('data.id');
        $b = $this->upload($item, 'b.jpg')->json('data.id');

        $this->putJson("/api/admin/items/{$item->id}/photos/order", ['photos' => [$b, $a], 'cover_id' => $b])
            ->assertOk()
            ->assertJsonPath('data.0.id', $b)
            ->assertJsonPath('data.0.is_cover', true)
            ->assertJsonPath('data.1.is_cover', false);

        // id foto item lain ditolak.
        $other = ItemPhoto::create(['item_id' => $this->otherItem($item)->id, 'path' => 'x.jpg']);
        $this->putJson("/api/admin/items/{$item->id}/photos/order", ['photos' => [$other->id]])
            ->assertUnprocessable();
    }

    public function test_delete_removes_file_and_promotes_next_cover(): void
    {
        $item = $this->item();
        $a = $this->upload($item)->json('data.id');
        $b = $this->upload($item, 'b.jpg')->json('data.id');
        $path = ItemPhoto::findOrFail($a)->path;

        $this->deleteJson("/api/admin/items/{$item->id}/photos/{$a}")->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertTrue(ItemPhoto::findOrFail($b)->is_cover);

        // Foto item lain lewat URL item ini → 404 (scoped binding).
        $other = ItemPhoto::create(['item_id' => $this->otherItem($item)->id, 'path' => 'x.jpg']);
        $this->deleteJson("/api/admin/items/{$item->id}/photos/{$other->id}")->assertNotFound();
    }

    public function test_role_without_items_update_is_forbidden(): void
    {
        $item = $this->item();
        $stakeholder = User::factory()->create(['tenant_id' => $item->tenant_id]);
        $stakeholder->assignRole('stakeholder');
        Sanctum::actingAs($stakeholder);

        $this->upload($item)->assertForbidden();
    }
}
