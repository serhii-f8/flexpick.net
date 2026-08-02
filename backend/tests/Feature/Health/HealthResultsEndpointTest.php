<?php

namespace Tests\Feature\Health;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Tests\Feature\FeatureTest;

class HealthResultsEndpointTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.endpoint_token', 'secret-token');
        config()->set('health.flexpick.result_freshness_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fakeResults(string $finishedAt, array $checks): void
    {
        $stored = new StoredCheckResults(
            finishedAt: Carbon::parse($finishedAt),
            checkResults: new Collection(array_map(
                fn (array $c) => new StoredCheckResult(
                    name: $c['name'],
                    label: $c['name'],
                    notificationMessage: $c['message'] ?? '',
                    shortSummary: $c['message'] ?? '',
                    status: $c['status'],
                    meta: [],
                ),
                $checks
            )),
        );

        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn($stored);
    }

    public function test_returns_200_when_fresh_and_all_ok(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Database', 'status' => 'ok'],
            ['name' => 'Cache', 'status' => 'ok'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertOk()
            ->assertJson(['stale' => false]);
    }

    public function test_returns_503_when_a_critical_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);
    }

    public function test_returns_503_when_a_high_band_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'MailFailureRate', 'status' => 'failed', 'message' => '60%'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);
    }

    public function test_returns_200_when_only_a_medium_band_check_fails(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'UsedDiskSpace', 'status' => 'failed', 'message' => '90%'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertOk()
            ->assertJsonFragment(['name' => 'UsedDiskSpace', 'status' => 'failed']);
    }

    public function test_a_crashed_check_behaves_as_a_failure_of_the_same_band(): void
    {
        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Redis', 'status' => 'crashed'],
        ]);

        $this->getJson('/health?token=secret-token')->assertStatus(503);

        $this->fakeResults('2026-08-02 11:58:00', [
            ['name' => 'Cache', 'status' => 'crashed'],
        ]);

        $this->getJson('/health?token=secret-token')->assertOk();
    }

    public function test_returns_503_when_results_are_stale(): void
    {
        $this->fakeResults('2026-08-02 11:40:00', [
            ['name' => 'Database', 'status' => 'ok'],
        ]);

        $this->getJson('/health?token=secret-token')
            ->assertStatus(503)
            ->assertJson(['stale' => true]);
    }

    public function test_returns_503_when_no_results_exist(): void
    {
        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn(null);

        $this->getJson('/health?token=secret-token')
            ->assertStatus(503)
            ->assertJson(['status' => 'no_results']);
    }

    public function test_wrong_token_returns_404(): void
    {
        $this->withExceptionHandling();

        $this->getJson('/health?token=wrong')->assertNotFound();
    }

    public function test_absent_token_returns_404(): void
    {
        $this->withExceptionHandling();

        $this->getJson('/health')->assertNotFound();
    }

    public function test_unconfigured_token_returns_404(): void
    {
        $this->withExceptionHandling();
        config()->set('health.flexpick.endpoint_token', null);

        $this->getJson('/health?token=')->assertNotFound();
    }
}
