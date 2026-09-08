<?php

namespace Tests\Unit\Http;

use App\Http\Concerns\ApiResponses;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Kontrak bentuk response JSON (dok: ApiResponses). Tanpa DB — murni bentuk
 * payload, karena seluruh FE bergantung pada kunci `success`/`data`/`meta`.
 */
class ApiResponsesTest extends TestCase
{
    private object $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new class
        {
            use ApiResponses {
                ok as public;
                created as public;
                noContent as public;
                error as public;
                paginated as public;
            }
        };
    }

    public function test_ok_wraps_data_with_success_true(): void
    {
        $response = $this->responder->ok(['id' => 1], 'Berhasil');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Berhasil',
            'data' => ['id' => 1],
        ], $response->getData(true));
    }

    public function test_ok_defaults_to_null_data_and_generic_message(): void
    {
        $payload = $this->responder->ok()->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('OK', $payload['message']);
        $this->assertNull($payload['data']);
    }

    public function test_created_uses_status_201(): void
    {
        $response = $this->responder->created(['id' => 9]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Data berhasil dibuat', $response->getData(true)['message']);
    }

    public function test_no_content_returns_204_without_body(): void
    {
        $response = $this->responder->noContent();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $response->getData(true));
    }

    public function test_error_omits_errors_key_when_not_given(): void
    {
        $response = $this->responder->error('Tidak ditemukan', 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Tidak ditemukan',
        ], $response->getData(true));
    }

    public function test_error_includes_errors_key_when_given(): void
    {
        $payload = $this->responder
            ->error('Validasi gagal', 422, ['name' => ['Wajib diisi.']])
            ->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame(['name' => ['Wajib diisi.']], $payload['errors']);
    }

    public function test_paginated_builds_meta_from_paginator(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [['id' => 3], ['id' => 4]],
            total: 7,
            perPage: 2,
            currentPage: 2,
        );

        $payload = $this->responder->paginated($paginator)->getData(true);

        $this->assertSame([['id' => 3], ['id' => 4]], $payload['data']);
        $this->assertSame([
            'current_page' => 2,
            'per_page' => 2,
            'total' => 7,
            'last_page' => 4,
            'from' => 3,
            'to' => 4,
        ], $payload['meta']);
    }

    public function test_paginated_unwraps_resource_collection(): void
    {
        $facility = new Facility(['name' => 'Kolam Renang', 'icon' => 'pool']);
        $facility->forceFill(['id' => 1]);

        $paginator = new LengthAwarePaginator([$facility], total: 1, perPage: 15, currentPage: 1);

        $payload = $this->responder
            ->paginated(FacilityResource::collection($paginator))
            ->getData(true);

        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame('Kolam Renang', $payload['data'][0]['name']);
    }
}
