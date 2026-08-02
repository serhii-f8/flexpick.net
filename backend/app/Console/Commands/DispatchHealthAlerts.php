<?php

namespace App\Console\Commands;

use App\Notifications\OperationsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
            $this->dispatchFor($result);
        }

        return self::SUCCESS;
    }

    private function dispatchFor(StoredCheckResult $result): void
    {
        $isFailing = in_array($result->status, self::FAILING, true);
        $key = "health:alert:{$result->name}";
        $lastAlertedAt = $this->remember($key);

        if (! $isFailing) {
            // Recovery: only interesting if we previously alerted.
            if ($lastAlertedAt !== null) {
                $this->send($result, 'ok');
                $this->forget($key);
            }

            return;
        }

        $throttleMinutes = (int) config('health.flexpick.alert_throttle_minutes');

        if ($lastAlertedAt !== null && now()->diffInMinutes($lastAlertedAt, true) < $throttleMinutes) {
            return;
        }

        $this->send($result, $result->status);
        $this->store($key);
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
