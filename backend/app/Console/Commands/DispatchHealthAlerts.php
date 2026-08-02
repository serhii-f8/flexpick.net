<?php

namespace App\Console\Commands;

use App\Notifications\OperationsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Throwable;

/**
 * Owns alert dispatch so three spec requirements hold that Spatie's built-in
 * notification config cannot satisfy: band-aware messages, guaranteed recovery
 * notifications, and a throttle whose failure mode is to SEND, not to suppress.
 */
class DispatchHealthAlerts extends Command
{
    protected $signature = 'app:health-alerts';

    protected $description = 'Dispatch operational alerts for health check state transitions';

    private const FAILING = ['failed', 'crashed'];

    public function handle(ResultStore $resultStore): int
    {
        $results = $resultStore->latestResults();

        if ($results === null) {
            // Nothing to alert on, but the absence itself is a signal: the
            // scheduler is running this command while health:check is not
            // storing anything.
            Log::error('Health results are stale: no stored results exist, so health:check is not storing results.');
            $this->warn('No stored health results; nothing to dispatch.');

            return self::SUCCESS;
        }

        $this->warnIfStale($results);

        foreach ($results->storedCheckResults as $result) {
            try {
                $this->dispatchFor($result);
            } catch (Throwable $e) {
                // Defense in depth: every channel already swallows its own
                // failures, but if something else in this check's dispatch
                // throws, it must not stop alerts for the checks after it.
                Log::warning("Health alert dispatch failed for {$result->name}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * If health:check stops storing results while the scheduler keeps running
     * this command, the same stale "ok" set would be re-read forever with no
     * in-app signal. Log it, but keep going: the stored results are still the
     * best information available, and alerting on every check because of one
     * stale read would be worse than the staleness itself. The /health
     * endpoint's staleness arm remains the external dead-man's switch.
     */
    private function warnIfStale(StoredCheckResults $results): void
    {
        $freshnessMinutes = (int) config('health.flexpick.result_freshness_minutes');
        $ageMinutes = (int) Carbon::instance($results->finishedAt)->diffInMinutes(now(), true);

        if ($ageMinutes > $freshnessMinutes) {
            Log::error(
                "Health results are stale: the newest stored run finished {$ageMinutes} minutes ago, "
                ."beyond the {$freshnessMinutes} minute freshness window. health:check may not be running."
            );

            $this->warn("Health results are {$ageMinutes} minutes old (limit {$freshnessMinutes}).");
        }
    }

    private function dispatchFor(StoredCheckResult $result): void
    {
        $key = "health:alert:{$result->name}";

        if (in_array($result->status, self::FAILING, true)) {
            $this->dispatchFailing($result, $key);

            return;
        }

        if ($result->status === 'ok') {
            $this->dispatchRecovery($result, $key);

            return;
        }

        // A status such as 'warning' or 'skipped' is neither a failure nor a
        // recovery: the check is still degraded, so claiming "healthy again"
        // here would be worse than saying nothing, and clearing the throttle
        // key would let a later failure bypass the throttle window entirely.
        // Do nothing: no alert, throttle key untouched.
    }

    private function dispatchFailing(StoredCheckResult $result, string $key): void
    {
        $lastAlertedAt = $this->remember($key);
        $throttleMinutes = (int) config('health.flexpick.alert_throttle_minutes');

        if ($lastAlertedAt !== null && now()->diffInMinutes($lastAlertedAt, true) < $throttleMinutes) {
            return;
        }

        $this->send($result, $result->status);
        $this->store($key);
    }

    private function dispatchRecovery(StoredCheckResult $result, string $key): void
    {
        // Recovery is only interesting if we previously alerted.
        if ($this->remember($key) !== null) {
            $this->send($result, 'ok');
            $this->forget($key);
        }
    }

    private function send(StoredCheckResult $result, string $status): void
    {
        $bands = (array) config('health.flexpick.bands');
        $band = (string) ($bands[$result->name] ?? config('health.flexpick.default_band'));

        $message = $status === 'ok'
            ? "{$result->name} is healthy again."
            : ($result->notificationMessage ?: "{$result->name} reported {$status}.");

        $channels = array_intersect_key(
            OperationsAlert::CHANNEL_MAP,
            array_flip(array_map('strval', (array) config('health.flexpick.alert_channels')))
        );

        if ($channels === []) {
            // The alert is still dispatched (so a fake/observer can see it),
            // but with no channel resolved it reaches nobody. Say so.
            Log::warning(
                "No alert channels resolved for {$result->name}; this alert will not reach anyone. "
                .'Check HEALTH_ALERT_CHANNELS.'
            );
        }

        Notification::route('mail', (string) config('health.flexpick.mail.to'))
            ->notify(new OperationsAlert($result->name, $band, $status, $message));

        $this->line("Alerted: {$result->name} ({$status})");
    }

    /**
     * Throttle state reads must never suppress an alert. If the cache is
     * unreachable, return null so the caller treats this as "not yet alerted"
     * and sends.
     */
    private function remember(string $key): ?\Illuminate\Support\Carbon
    {
        try {
            $value = Cache::get($key);

            return $value === null ? null : \Illuminate\Support\Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function store(string $key): void
    {
        try {
            Cache::put($key, now()->toIso8601String(), now()->addDay());
        } catch (Throwable) {
            // Losing throttle state means a repeat alert, which is the safe direction.
        }
    }

    private function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // Non-fatal.
        }
    }
}
