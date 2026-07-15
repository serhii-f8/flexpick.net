<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditMailerTest extends FeatureTest
{
    public function test_sends_via_mailcoach_when_configured(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response(['data' => ['uuid' => 'tm-42']], 200)]);
        Mail::fake();

        $request = AuditRequest::factory()->create(['email' => 'client@example.com']);
        $mailable = new AuditRequestReceived($request, 'https://status.example');

        $log = app(AuditMailer::class)->send($mailable, $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertSame('tm-42', $log->mailcoach_uuid);
        $this->assertSame('AuditRequestReceived', $log->mailable);
        $this->assertSame(1, $log->attempts);
        $this->assertNotSame('', $log->body);
        Mail::assertNothingOutgoing();
    }

    public function test_falls_back_to_mail_when_mailcoach_unreachable(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/send' => Http::response('down', 500)]);
        Mail::fake();

        $request = AuditRequest::factory()->create();

        $log = app(AuditMailer::class)->send(new AuditRequestReceived($request, 'https://s'), $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->mailcoach_uuid);
        $this->assertStringContainsString('500', (string) $log->last_error);
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_sends_directly_when_unconfigured_without_http_calls(): void
    {
        config()->set('services.mailcoach.endpoint', null);
        Http::fake();
        Mail::fake();

        $request = AuditRequest::factory()->create();

        $log = app(AuditMailer::class)->send(new AuditRequestReceived($request, 'https://s'), $request->email, $request);

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        Http::assertNothingSent();
        Mail::assertQueued(AuditRequestReceived::class);
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
}
