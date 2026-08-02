<?php

namespace App\Console\Commands;

use App\Notifications\OperationsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
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
            $this->warn('No stored health results; nothing to dispatch.');

            return self::SUCCESS;
        }

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
