<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

/**
 * Laravel raises InvalidSignatureException for an expired signature and for a
 * damaged one alike. Reporting both as "expired" tells a customer whose mail
 * client mangled the link to wait for a new one they do not need — and on the
 * verification route, points them at a report email that does not exist yet.
 */
class SignedLinkFailureTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest disables exception handling, which bypasses the render
        // callback under test — these assertions are about what the customer
        // is shown, so the handler has to be in the loop.
        $this->withExceptionHandling();
    }

    /** Strips the signature, exactly as a truncating mail client would. */
    private function withoutSignature(string $url): string
    {
        return (string) preg_replace('/[?&]signature=[^&]*/', '', $url);
    }

    private function auditRequest(): AuditRequest
    {
        return AuditRequest::factory()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);
    }

    public function test_an_expired_report_link_reports_expiry(): void
    {
        $report = AuditReport::factory()->locked()->create();

        $url = URL::temporarySignedRoute(
            'reports.view',
            now()->subDay(),
            ['auditReport' => $report->uuid],
        );

        $this->get($url)
            ->assertStatus(403)
            ->assertSee(__('This report link has expired'))
            ->assertDontSee(__('This report link looks incomplete'));
    }

    public function test_a_damaged_report_link_is_not_reported_as_expired(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $this->get($this->withoutSignature($url))
            ->assertStatus(403)
            ->assertSee(__('This report link looks incomplete'))
            ->assertDontSee(__('This report link has expired'));
    }

    public function test_an_expired_verification_link_reports_expiry(): void
    {
        $auditRequest = $this->auditRequest();

        $url = URL::temporarySignedRoute(
            'audit-requests.verify',
            now()->subDay(),
            ['auditRequest' => $auditRequest->uuid],
        );

        $this->get($url)
            ->assertStatus(403)
            ->assertSee(__('This verification link has expired'))
            // Never send an unverified visitor to a report email they have
            // not been sent: the request is still pending verification.
            ->assertDontSee(__('Reply to your report email and we\'ll send you a fresh one.', ['days' => config('audit.report_link_days')]));
    }

    public function test_a_damaged_verification_link_is_not_reported_as_expired(): void
    {
        $auditRequest = $this->auditRequest();
        $url = app(AuditRequestService::class)->verificationUrl($auditRequest);

        $this->get($this->withoutSignature($url))
            ->assertStatus(403)
            ->assertSee(__('This verification link looks incomplete'))
            ->assertDontSee(__('This verification link has expired'));
    }

    /**
     * The HTML-escaped ampersand a customer gets by copying the link out of
     * the email source: `signature` is parsed as `amp;signature`, so the
     * signature is absent while `expires` still reads as current.
     */
    public function test_an_html_escaped_ampersand_is_reported_as_damaged_not_expired(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = str_replace('&', '&amp;', app(AuditReportService::class)->signedUrl($report));

        $this->get($url)
            ->assertStatus(403)
            ->assertSee(__('This report link looks incomplete'))
            ->assertDontSee(__('This report link has expired'));
    }

    public function test_a_valid_report_link_still_renders(): void
    {
        $report = AuditReport::factory()->locked()->create();

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertDontSee(__('This report link has expired'))
            ->assertDontSee(__('This report link looks incomplete'));
    }

    public function test_a_valid_verification_link_still_verifies(): void
    {
        $auditRequest = $this->auditRequest();

        $this->get(app(AuditRequestService::class)->verificationUrl($auditRequest))
            ->assertRedirect();

        $this->assertNotSame(
            AuditRequestStatus::PENDING_VERIFICATION->value,
            $auditRequest->fresh()->status,
        );
    }
}
