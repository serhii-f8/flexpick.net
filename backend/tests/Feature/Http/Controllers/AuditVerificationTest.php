<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Mail\Audit\AuditVerifyEmail;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditVerificationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['audit.admin_email' => 'admin@flexpick.net']);
    }

    public function test_submit_creates_pending_request_and_sends_only_verification_email(): void
    {
        $response = $this->postJson('/api/audit-requests', [
            'name' => 'Ada', 'email' => 'ada-verify@example.com',
            'repo_url' => 'https://github.com/example/repo',
            'marketing_consent' => true,
        ]);

        $response->assertCreated();
        $request = AuditRequest::where('email', 'ada-verify@example.com')->firstOrFail();
        $this->assertSame(AuditRequestStatus::PENDING_VERIFICATION->value, $request->status);
        $this->assertNull($request->email_verified_at);
        $this->assertTrue($request->marketing_consent);
        $this->assertNotNull($request->consented_at);
        Mail::assertQueued(AuditVerifyEmail::class, fn ($mail) => $mail->hasTo('ada-verify@example.com'));
        Mail::assertNotQueued(NewAuditRequestAdminNotification::class);
        Queue::assertNothingPushed();
    }

    public function test_submit_without_consent_stores_false(): void
    {
        $this->postJson('/api/audit-requests', ['name' => 'Bob', 'email' => 'bob@example.com'])->assertCreated();

        $request = AuditRequest::where('email', 'bob@example.com')->firstOrFail();
        $this->assertFalse($request->marketing_consent);
        $this->assertNull($request->consented_at);
    }

    public function test_signed_verify_link_marks_verified_and_dispatches_routing(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);

        $service = app(AuditRequestService::class);
        $url = $service->verificationUrl($request);
        $this->get($url)->assertRedirect($service->statusUrl($request));

        $this->assertNotNull($request->refresh()->email_verified_at);
        Queue::assertPushed(RouteVerifiedAuditRequest::class, 1);
    }

    public function test_verify_is_idempotent(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);
        $service = app(AuditRequestService::class);
        $url = $service->verificationUrl($request);

        $this->get($url)->assertRedirect($service->statusUrl($request));
        $this->get($url)->assertRedirect($service->statusUrl($request));

        Queue::assertPushed(RouteVerifiedAuditRequest::class, 1);
    }

    public function test_unsigned_verify_link_is_rejected(): void
    {
        $this->withExceptionHandling();
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);

        $this->get("/audit-requests/{$request->uuid}/verify")->assertForbidden();
        $this->assertNull($request->refresh()->email_verified_at);
    }

    public function test_expired_verify_link_is_rejected(): void
    {
        $this->withExceptionHandling();
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);
        $url = URL::temporarySignedRoute('audit-requests.verify', now()->subMinute(), ['auditRequest' => $request->uuid]);

        $this->get($url)->assertForbidden();
        $this->assertNull($request->refresh()->email_verified_at);
    }
}
