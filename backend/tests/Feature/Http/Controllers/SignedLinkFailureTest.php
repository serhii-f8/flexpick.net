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
     * the email source, or that a mail client leaves behind: `signature` is
     * parsed as `amp;signature`. The separator is repaired and the link works.
     */
    public function test_an_html_escaped_ampersand_is_repaired_and_the_report_renders(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = str_replace('&', '&amp;', app(AuditReportService::class)->signedUrl($report));

        $repaired = $this->get($url);
        $repaired->assertRedirect();

        $this->get($repaired->headers->get('Location'))->assertOk();
    }

    public function test_an_html_escaped_verification_link_is_repaired_and_verifies(): void
    {
        $auditRequest = $this->auditRequest();
        $url = str_replace('&', '&amp;', app(AuditRequestService::class)->verificationUrl($auditRequest));

        $repaired = $this->get($url);
        $repaired->assertRedirect();

        $this->get($repaired->headers->get('Location'))->assertRedirect();

        $this->assertNotSame(
            AuditRequestStatus::PENDING_VERIFICATION->value,
            $auditRequest->fresh()->status,
        );
    }

    /**
     * Repairing the separator must not become a way in. A link whose signature
     * was altered is still rejected after the ampersands are fixed — the
     * middleware restores the transport, never the trust.
     */
    public function test_a_forged_signature_is_still_rejected_after_repair(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $signed = app(AuditReportService::class)->signedUrl($report);
        $forged = preg_replace('/signature=[0-9a-f]{8}/', 'signature=deadbeef', $signed);

        $repaired = $this->get(str_replace('&', '&amp;', (string) $forged));
        $repaired->assertRedirect();

        $this->get($repaired->headers->get('Location'))
            ->assertStatus(403)
            ->assertSee(__('This report link looks incomplete'));
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
