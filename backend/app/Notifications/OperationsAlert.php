<?php

namespace App\Notifications;

use App\Notifications\Channels\MailAlertChannel;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class OperationsAlert extends Notification
{
    /**
     * The only channel names `health.flexpick.alert_channels` may contain.
     *
     * @var array<string, class-string>
     */
    public const CHANNEL_MAP = [
        'mail' => MailAlertChannel::class,
        'telegram' => TelegramChannel::class,
        'slack' => SlackWebhookChannel::class,
    ];

    public function __construct(
        public readonly string $checkName,
        public readonly string $band,
        public readonly string $status,
        public readonly string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $resolved = [];

        foreach ((array) config('health.flexpick.alert_channels') as $name) {
            $channel = self::CHANNEL_MAP[(string) $name] ?? null;

            if ($channel === null) {
                // A typo in HEALTH_ALERT_CHANNELS would otherwise drop the
                // channel silently — the exact failure mode this alerting
                // path exists to eliminate.
                Log::warning(sprintf(
                    'OperationsAlert: unknown alert channel "%s" in HEALTH_ALERT_CHANNELS; it will be ignored. Known channels: %s.',
                    (string) $name,
                    implode(', ', array_keys(self::CHANNEL_MAP))
                ));

                continue;
            }

            $resolved[] = $channel;
        }

        return $resolved;
    }

    public function subject(): string
    {
        $verb = $this->status === 'ok' ? 'RECOVERED' : 'FAILING';

        return sprintf('[%s] %s %s', strtoupper($this->band), $this->checkName, $verb);
    }

    public function toAlertText(): string
    {
        return $this->subject()."\n\n".$this->message."\n\n".config('app.url');
    }
}
