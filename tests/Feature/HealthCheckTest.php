<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'up',
                    'database' => 'ok',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['service', 'status', 'database', 'time'],
            ]);
    }
}
