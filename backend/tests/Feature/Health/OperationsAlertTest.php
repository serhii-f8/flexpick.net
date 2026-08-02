<?php

namespace Tests\Feature\Health;

use App\Notifications\Channels\MailAlertChannel;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\OperationsAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\FeatureTest;

class OperationsAlertTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('health.flexpick.telegram', [
            'bot_token' => 'bot-token',
            'chat_id' => '12345',
        ]);
        config()->set('health.flexpick.slack.webhook_url', 'https://hooks.slack.test/abc');
        config()->set('health.flexpick.mail.to', 'ops@example.com');
        config()->set('health.flexpick.channel_timeout_seconds', 5);
    }

    private function alert(): OperationsAlert
    {
        return new OperationsAlert(
            checkName: 'OldestPendingAudit',
            band: 'critical',
            status: 'failed',
            message: 'Oldest queued audit has been waiting 45 minutes (limit 30).',
        );
    }

    public function test_via_returns_only_the_configured_channels(): void
    {
        config()->set('health.flexpick.alert_channels', ['mail', 'telegram']);

        $via = $this->alert()->via(Notification::route('mail', 'ops@example.com'));

        $this->assertContains(MailAlertChannel::class, $via);
        $this->assertContains(TelegramChannel::class, $via);
        $this->assertNotContains(SlackWebhookChannel::class, $via);
    }

    public function test_telegram_channel_posts_the_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botbot-token/sendMessage')
                && $request['chat_id'] === '12345'
                && str_contains($request['text'], 'CRITICAL')
                && str_contains($request['text'], 'OldestPendingAudit')
                && str_contains($request['text'], '45 minutes');
        });
    }

    public function test_slack_channel_posts_the_message(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        (new SlackWebhookChannel)->send(
            Notification::route(SlackWebhookChannel::class, null),
            $this->alert()
        );

        Http::assertSent(fn ($request) => str_contains($request['text'], 'OldestPendingAudit'));
    }

    /**
     * An alerting path that throws converts a degraded system into a silent
     * one — the exact failure this phase exists to prevent.
     */
    public function test_channel_without_credentials_is_skipped_and_logged_not_thrown(): void
    {
        Http::preventStrayRequests();
        config()->set('health.flexpick.telegram.bot_token', null);

        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Telegram')
        );

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );
    }

    public function test_channel_transport_failure_is_swallowed(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));
        Log::shouldReceive('warning')->once();

        (new TelegramChannel)->send(
            Notification::route(TelegramChannel::class, null),
            $this->alert()
        );
    }

    /**
     * Mail must be just as self-guarding as Telegram/Slack: an unconfigured
     * destination logs a warning instead of silently dropping the alert, and
     * a transport failure is caught rather than rethrown (the framework's
     * MailChannel/NotificationSender otherwise rethrows mail failures, which
     * would kill the remaining channels and every later check in the run).
     */
    public function test_mail_channel_without_destination_is_skipped_and_logged_not_thrown(): void
    {
        config()->set('health.flexpick.mail.to', null);

        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Mail')
        );

        (new MailAlertChannel)->send(
            Notification::route(MailAlertChannel::class, null),
            $this->alert()
        );
    }

    public function test_mail_channel_transport_failure_is_swallowed(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Mail')
        );

        (new MailAlertChannel)->send(
            Notification::route(MailAlertChannel::class, null),
            $this->alert()
        );
    }
}
