<?php

namespace Tests\Feature\Services;

use App\Mail\TestEmail;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Services\Fixtures\UnrenderableMailable;
use Tests\TestCase;
use Throwable;

class RenderSafeMailerTest extends TestCase
{
    public function test_successful_send_dispatches_mail(): void
    {
        Mail::fake();

        app(RenderSafeMailer::class)->send(
            new TestEmail('Test subject', '<p>body</p>'),
            'success@example.com'
        );

        Mail::assertQueued(TestEmail::class);
    }

    public function test_render_failure_logs_error_with_context_and_rethrows(): void
    {
        Mail::fake();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Mail render failed')
                    && $context['mailable'] === 'UnrenderableMailable'
                    && $context['recipient'] === 'render-fail@example.com'
                    && isset($context['exception']);
            });

        Log::makePartial();

        $caught = false;

        try {
            app(RenderSafeMailer::class)->send(
                new UnrenderableMailable,
                'render-fail@example.com'
            );
        } catch (Throwable) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected the render failure to be rethrown.');
        Mail::assertNothingOutgoing();
    }

    public function test_send_failure_logs_error_with_context_and_rethrows(): void
    {
        config(['queue.default' => 'no-such-connection']);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Mail send failed')
                    && $context['mailable'] === 'TestEmail'
                    && $context['recipient'] === 'send-fail@example.com'
                    && isset($context['exception']);
            });

        Log::makePartial();

        $caught = false;

        try {
            app(RenderSafeMailer::class)->send(
                new TestEmail('Test subject', '<p>body</p>'),
                'send-fail@example.com'
            );
        } catch (Throwable) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected the send failure to be rethrown.');
    }
}
