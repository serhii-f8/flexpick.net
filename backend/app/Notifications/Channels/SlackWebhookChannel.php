<?php

namespace App\Notifications\Channels;

use App\Notifications\OperationsAlert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackWebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OperationsAlert) {
            return;
        }

        $webhook = (string) config('health.flexpick.slack.webhook_url');

        if ($webhook === '') {
            Log::warning('Health alert channel Slack is enabled but not configured; skipping.');

            return;
        }

        try {
            Http::timeout((int) config('health.flexpick.channel_timeout_seconds'))
                ->post($webhook, ['text' => $notification->toAlertText()]);
        } catch (Throwable $e) {
            Log::warning('Health alert channel Slack failed: '.$e->getMessage());
        }
    }
}
