<?php

namespace Tests\Feature\Health;

use App\Notifications\OperationsAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Tests\Feature\FeatureTest;

class DispatchHealthAlertsTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-02 12:00:00');
        Cache::flush();
        config()->set('health.flexpick.alert_channels', ['mail']);
        config()->set('health.flexpick.mail.to', 'ops@example.com');
        config()->set('health.flexpick.alert_throttle_minutes', 60);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function results(array $checks): void
    {
        $stored = new StoredCheckResults(
            finishedAt: now(),
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

    public function test_sends_an_alert_when_a_check_starts_failing(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(
            OperationsAlert::class,
            fn (OperationsAlert $alert) => $alert->checkName === 'Database'
                && $alert->band === 'critical'
                && $alert->status === 'failed'
        );
    }

    public function test_sends_nothing_when_everything_is_ok(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'ok']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_does_not_repeat_within_the_throttle_window(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts');
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 1);
    }

    public function test_repeats_after_the_throttle_window_expires(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        $this->artisan('app:health-alerts');
        Carbon::setTestNow(now()->addMinutes(61));
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 2);
    }

    public function test_sends_a_recovery_alert_when_a_failing_check_returns_to_ok(): void
    {
        Notification::fake();

        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);
        $this->artisan('app:health-alerts');

        $this->results([['name' => 'Database', 'status' => 'ok']]);
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemand(
            OperationsAlert::class,
            fn (OperationsAlert $alert) => $alert->status === 'ok'
        );
    }

    public function test_a_crashed_check_alerts_like_a_failure(): void
    {
        Notification::fake();
        $this->results([['name' => 'Redis', 'status' => 'crashed']]);

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    /**
     * Spec: a throttle lookup failure must fall through to SENDING. The cache
     * being unavailable is exactly when the alert matters most.
     */
    public function test_sends_when_the_throttle_store_is_unavailable(): void
    {
        Notification::fake();
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis down'));

        $this->artisan('app:health-alerts')->assertSuccessful();

        Notification::assertSentOnDemand(OperationsAlert::class);
    }
}
