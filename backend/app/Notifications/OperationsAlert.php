<?php

namespace App\Notifications;

use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationsAlert extends Notification
{
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
        $map = [
            'mail' => 'mail',
            'telegram' => TelegramChannel::class,
            'slack' => SlackWebhookChannel::class,
        ];

        $configured = (array) config('health.flexpick.alert_channels');

        return array_values(array_filter(array_map(
            fn (string $name) => $map[$name] ?? null,
            $configured
        )));
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

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->message)
            ->line('Check: '.$this->checkName)
            ->line('Severity: '.strtoupper($this->band));
    }
}
