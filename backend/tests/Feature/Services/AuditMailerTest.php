<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\Feature\FeatureTest;
use Tests\Feature\Services\Fixtures\UnrenderableMailable;
use Throwable;

class AuditMailerTest extends FeatureTest
{
    public function test_sends_and_logs_sent_row(): void
    {
        Mail::fake();

        $request = AuditRequest::factory()->create(['email' => 'direct-send@example.com']);

        $log = app(AuditMailer::class)->send(
            new AuditRequestReceived($request, 'https://status.example'),
            $request->email,
            $request
        );

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertSame('AuditRequestReceived', $log->mailable);
        $this->assertSame(1, $log->attempts);
        $this->assertNotSame('', $log->body);
        $this->assertNull($log->last_error);
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_send_failure_logs_failed_row_and_rethrows(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'send-fail@example.com']);

        // Every audit mailable implements ShouldQueue, so AuditMailer's Mail::to()->send()
        // hands the message to the queue rather than straight to the transport. Pointing the
        // queue at a connection that does not exist makes that dispatch throw synchronously —
        // no mocks, no network, and it mirrors a real failure mode (queue backend unavailable).
        config(['queue.default' => 'no-such-connection']);

        try {
            app(AuditMailer::class)->send(
                new AuditRequestReceived($request, 'https://status.example'),
                $request->email,
                $request
            );
            $this->fail('Expected the send failure to be rethrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no-such-connection', $e->getMessage());
        }

        $log = AuditEmailLog::where('audit_request_id', $request->id)->sole();

        $this->assertSame(AuditEmailLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('no-such-connection', (string) $log->last_error);
        $this->assertNotSame('', $log->body, 'the message rendered before the dispatch failed');
    }

    public function test_call_sites_create_log_rows(): void
    {
        Mail::fake();

        $request = app(AuditRequestService::class)->submit([
            'name' => 'Ada',
            'email' => 'call-site-log@example.com',
        ]);

        $this->assertSame(1, AuditEmailLog::where('audit_request_id', $request->id)->where('mailable', 'AuditVerifyEmail')->count());
    }

    public function test_render_failure_logs_failed_row_and_rethrows(): void
    {
        Mail::fake();

        $request = AuditRequest::factory()->create(['email' => 'render-fail@example.com']);

        try {
            app(AuditMailer::class)->send(new UnrenderableMailable, $request->email, $request);
            $this->fail('Expected the render failure to be rethrown.');
        } catch (Throwable $e) {
            // Expected — the mailer must rethrow after recording the failure.
        }

        $log = AuditEmailLog::where('audit_request_id', $request->id)->sole();

        $this->assertSame(AuditEmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('UnrenderableMailable', $log->mailable);
        $this->assertSame('render-fail@example.com', $log->recipient);
        $this->assertStringStartsWith('Render failed: ', (string) $log->last_error);
        Mail::assertNothingOutgoing();
    }
}
