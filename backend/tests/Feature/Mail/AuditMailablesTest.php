<?php

namespace Tests\Feature\Mail;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditMailablesTest extends FeatureTest
{
    public function test_received_mailable_renders(): void
    {
        $request = AuditRequest::factory()->create();

        $mailable = new AuditRequestReceived($request, 'https://app.example.com/audit-requests/abc/status?signature=x');
        $mailable->assertSeeInHtml($request->name);
    }

    /**
     * The customer invites us and then runs a new audit themselves -- the
     * email must say so, and must not promise that we restart this one.
     */
    public function test_access_needed_mailable_tells_the_customer_to_run_a_new_audit(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'repo_url' => 'https://github.com/acme/private-app',
            'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
            'tenant_id' => $tenant->id,
        ]);

        $mailable = new AuditRepoAccessNeeded($request);

        $mailable->assertSeeInHtml(config('audit.github_account'));
        $mailable->assertSeeInHtml('run a new audit');
        $mailable->assertSeeInHtml('won\'t restart on its own');
        $mailable->assertSeeInHtml('haven\'t been charged');
        $mailable->assertSeeInHtml('Run the audit again');
        $mailable->assertSeeInHtml('repo=https%3A%2F%2Fgithub.com%2Facme%2Fprivate-app');
        $mailable->assertDontSeeInHtml('as soon as the invite is accepted');
    }

    public function test_access_needed_mailable_sends_a_workspaceless_visitor_to_sign_in(): void
    {
        $request = AuditRequest::factory()->create([
            'repo_url' => 'https://github.com/acme/private-app',
            'tenant_id' => null,
        ]);

        $mailable = new AuditRepoAccessNeeded($request);

        $mailable->assertSeeInHtml(route('login'));
    }

    public function test_access_needed_mailable_explains_a_non_access_failure_without_invite_steps(): void
    {
        $request = AuditRequest::factory()->create([
            'repo_url' => 'https://github.com/acme/huge-app',
            'failure_reason' => 'Repository too large for automated analysis (900 MB)',
        ]);

        $mailable = new AuditRepoAccessNeeded($request, accessProblem: false);

        $mailable->assertSeeInHtml('Repository too large for automated analysis (900 MB)');
        $mailable->assertSeeInHtml('haven\'t been charged');
        $mailable->assertDontSeeInHtml('Collaborators');
    }

    public function test_report_ready_attaches_pdf_and_links(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 fixture');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf']);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')
                ->as('codebase-health-report.pdf')
                ->withMime('application/pdf')
        );
    }

    /**
     * The price in this email must come from the catalog, not a literal: it is
     * the checkout price the reader is about to be charged, and a hardcoded
     * "$5" silently drifts the first time config/pricing.php changes.
     */
    public function test_quota_exhausted_mailable_quotes_the_catalog_diagnostic_price(): void
    {
        $request = AuditRequest::factory()->create();
        $price = number_format((int) AuditTier::DIAGNOSTIC->priceCents() / 100);

        $mailable = new AuditQuotaExhausted($request, 'https://app.example.com/audit-requests/abc/purchase-run?signature=x');

        $mailable->assertSeeInHtml('Run this audit now for $'.$price);
        // Nothing here may promise a free audit any more.
        $mailable->assertDontSeeInHtml('free audits');
        $mailable->assertDontSeeInHtml('free codebase');
    }

    public function test_quota_exhausted_mailable_price_tracks_a_catalog_change(): void
    {
        config(['pricing.tiers.audit-diagnostic.price' => 1200]);
        $request = AuditRequest::factory()->create();

        (new AuditQuotaExhausted($request, 'https://app.example.com/x'))
            ->assertSeeInHtml('Run this audit now for $12');
    }
}
