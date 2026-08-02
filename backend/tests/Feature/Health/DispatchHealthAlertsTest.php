<?php

namespace Tests\Feature\Health;

use App\Notifications\OperationsAlert;
use Carbon\Carbon;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    private function results(array $checks, ?\DateTimeInterface $finishedAt = null): void
    {
        $stored = new StoredCheckResults(
            finishedAt: $finishedAt ?? now(),
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

    /**
     * A 'warning' (or 'skipped') status is still degraded, not recovered —
     * claiming "healthy again" there would be worse than saying nothing. It
     * must also leave the throttle key alone, so a subsequent failure inside
     * the original window is still suppressed rather than re-alerted.
     */
    public function test_a_warning_after_a_failure_sends_no_recovery_and_keeps_the_throttle_key(): void
    {
        Notification::fake();

        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);
        $this->artisan('app:health-alerts');

        $this->results([['name' => 'Database', 'status' => 'warning', 'message' => 'degraded']]);
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 1);

        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable again']]);
        $this->artisan('app:health-alerts');

        Notification::assertSentOnDemandTimes(OperationsAlert::class, 1);
    }

    /**
     * If health:check stops storing results, this command would otherwise
     * re-read the same stale "ok" set forever with no in-app signal at all.
     */
    public function test_logs_an_error_when_the_stored_results_are_stale(): void
    {
        Notification::fake();
        config()->set('health.flexpick.result_freshness_minutes', 15);
        $this->results(
            [['name' => 'Database', 'status' => 'ok']],
            finishedAt: now()->subMinutes(60),
        );

        Log::spy();

        $this->artisan('app:health-alerts')->assertSuccessful();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'stale') && str_contains($message, '60')
        );
    }

    public function test_logs_an_error_when_no_results_are_stored_at_all(): void
    {
        $store = $this->mock(ResultStore::class);
        $store->shouldReceive('latestResults')->andReturn(null);

        Log::spy();

        $this->artisan('app:health-alerts')->assertSuccessful();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'stale')
        );
    }

    /**
     * A typo'd or empty HEALTH_ALERT_CHANNELS resolves to no channels, so the
     * alert reaches nobody. That must not be silent.
     */
    public function test_logs_a_warning_when_no_alert_channel_resolves(): void
    {
        Notification::fake();
        config()->set('health.flexpick.alert_channels', ['telegramm']);
        $this->results([['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable']]);

        Log::spy();

        $this->artisan('app:health-alerts')->assertSuccessful();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'No alert channels resolved')
                && str_contains($message, 'Database')
        )->once();

        // ...and the notification itself names the channel that did not resolve.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'telegramm')
        )->once();
    }

    /**
     * Defense in depth: every channel already swallows its own failures, but
     * the per-check dispatch itself is wrapped too, so a failure dispatching
     * one check's alert can never suppress alerts for the checks after it.
     */
    public function test_a_checks_alert_dispatch_failure_does_not_block_a_later_check(): void
    {
        $this->results([
            ['name' => 'Database', 'status' => 'failed', 'message' => 'unreachable'],
            ['name' => 'Redis', 'status' => 'crashed'],
        ]);

        Log::spy();

        // Notification::route() is a real static method on the facade, so the
        // interceptable seam is ChannelManager::send() — what the resulting
        // AnonymousNotifiable::notify() delegates to. Database (the first
        // check in the run) blows up there; Redis (the second) must still be
        // dispatched. This cannot be combined with Notification::fake(),
        // hence the mock and output assertions rather than fake assertions.
        Notification::shouldReceive('send')->once()->withArgs(
            fn (AnonymousNotifiable $to, OperationsAlert $alert) => $alert->checkName === 'Database'
                && $to->routeNotificationFor('mail') === 'ops@example.com'
        )->andThrow(new \RuntimeException('dispatch exploded'));

        Notification::shouldReceive('send')->once()->withArgs(
            fn (AnonymousNotifiable $to, OperationsAlert $alert) => $alert->checkName === 'Redis'
                && $alert->status === 'crashed'
                && $to->routeNotificationFor('mail') === 'ops@example.com'
        );

        $this->assertSame(0, Artisan::call('app:health-alerts'));

        $output = Artisan::output();
        $this->assertStringContainsString('Alerted: Redis', $output);
        $this->assertStringNotContainsString('Alerted: Database', $output);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Database')
                && str_contains($message, 'dispatch exploded')
        );
    }
}
