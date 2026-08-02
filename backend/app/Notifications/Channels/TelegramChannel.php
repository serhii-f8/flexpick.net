<?php

namespace App\Notifications\Channels;

use App\Notifications\OperationsAlert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OperationsAlert) {
            return;
        }

        $token = (string) config('health.flexpick.telegram.bot_token');
        $chatId = (string) config('health.flexpick.telegram.chat_id');

        if ($token === '' || $chatId === '') {
            Log::warning('Health alert channel Telegram is enabled but not configured; skipping.');

            return;
        }

        try {
            Http::timeout((int) config('health.flexpick.channel_timeout_seconds'))
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $notification->toAlertText(),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable $e) {
            // Never rethrow: one dead channel must not suppress the others,
            // nor crash the health run.
            Log::warning('Health alert channel Telegram failed: '.$e->getMessage());
        }
    }
}
