<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\FeatureTest;

class HealthReadinessTest extends FeatureTest
{
    public function test_reports_ready_when_database_and_cache_are_reachable(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJson([
            'ready' => true,
            'checks' => ['database' => true, 'cache' => true],
        ]);
    }

    public function test_reports_not_ready_with_503_when_database_is_unreachable(): void
    {
        DB::shouldReceive('select')->andThrow(new RuntimeException('database down'));

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJson([
            'ready' => false,
            'checks' => ['database' => false, 'cache' => true],
        ]);
    }

    public function test_reports_not_ready_with_503_when_cache_is_unwritable(): void
    {
        Cache::shouldReceive('put')->andReturn(true);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('forget')->andReturn(true);

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJson([
            'ready' => false,
            'checks' => ['database' => true, 'cache' => false],
        ]);
    }

    /**
     * Spec §15.5 [R]: a dependency health check must never gate readiness.
     * This test turns that rule into a guard — it fails if anyone ever adds
     * an outbound call to the readiness path.
     */
    public function test_makes_no_external_http_call(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/health/ready')->assertOk();
    }

    public function test_response_is_not_cacheable(): void
    {
        $this->getJson('/health/ready')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
