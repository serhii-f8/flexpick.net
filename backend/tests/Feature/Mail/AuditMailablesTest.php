<?php

namespace Tests\Feature\Mail;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditReportUnlocked;
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
     * The customer connects their own git account and then runs a new audit
     * themselves -- the email must say so, and must not promise that we
     * restart this one.
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

        $mailable->assertSeeInHtml('Connect your GitHub account');
        $mailable->assertSeeInHtml('run a new audit');
        $mailable->assertSeeInHtml('won\'t restart on its own');
        $mailable->assertSeeInHtml('haven\'t been charged');
        $mailable->assertSeeInHtml('Run the audit again');
        $mailable->assertSeeInHtml('repo=https%3A%2F%2Fgithub.com%2Facme%2Fprivate-app');
        $mailable->assertDontSeeInHtml('read-only collaborator');
        $mailable->assertDontSeeInHtml('business day');
    }

    /**
     * The email names the repo's own git provider -- a GitLab URL must not
     * be told to connect GitHub, and the retired invite-a-collaborator
     * language must be gone entirely.
     */
    public function test_access_failure_email_names_the_repos_provider(): void
    {
        $auditRequest = AuditRequest::factory()->create(['repo_url' => 'https://gitlab.com/acme/app']);

        $rendered = (new AuditRepoAccessNeeded($auditRequest, accessProblem: true))->render();

        $this->assertStringContainsString('Connect your GitLab account', $rendered);
        $this->assertStringNotContainsString('read-only collaborator', $rendered);
        $this->assertStringNotContainsString('business day', $rendered);
    }

    /**
     * The workspace had a connection and it no longer reads the repo: tell them
     * to reconnect it, and link the Git Connections page.
     */
    public function test_access_needed_mailable_asks_a_formerly_connected_workspace_to_reconnect(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'repo_url' => 'https://gitlab.com/acme/private-app',
            'tenant_id' => $tenant->id,
        ]);

        $rendered = (new AuditRepoAccessNeeded($request, accessProblem: true, reconnect: true))->render();

        $this->assertStringContainsString('Reconnect your GitLab account', $rendered);
        $this->assertStringContainsString(e(GitConnections::getUrl(panel: 'dashboard', tenant: $tenant)), $rendered);
        $this->assertStringNotContainsString('Connect your GitLab account', $rendered);
        $this->assertStringNotContainsString('it looks private', $rendered);
        $this->assertStringContainsString('Run the audit again', $rendered);
    }

    public function test_access_needed_mailable_keeps_the_connect_copy_without_a_connection(): void
    {
        $request = AuditRequest::factory()->create(['repo_url' => 'https://gitlab.com/acme/private-app']);

        $rendered = (new AuditRepoAccessNeeded($request, accessProblem: true))->render();

        $this->assertStringContainsString('Connect your GitLab account', $rendered);
        $this->assertStringNotContainsString('Reconnect', $rendered);
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

    public function test_report_ready_attaches_both_pdfs_and_links_both_reports(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 business');
        Storage::disk('local')->put('audit-reports/fixture-technical.pdf', '%PDF-1.4 technical');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf', 'technical_pdf_path' => 'audit-reports/fixture-technical.pdf']);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x', technicalUrl: 'https://app.example.com/reports/abc/technical?signature=y');

        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc/technical?signature=y');
        $mailable->assertSeeInHtml(__('Forward the developer report to your engineer'));
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')->as('codebase-health-business.pdf')->withMime('application/pdf')
        );
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture-technical.pdf')->as('codebase-health-developer.pdf')->withMime('application/pdf')
        );
    }

    public function test_report_ready_without_a_developer_pdf_attaches_only_the_business_one(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 business');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf', 'technical_pdf_path' => null]);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x');

        $this->assertCount(1, $mailable->attachments());
        $mailable->assertDontSeeInHtml(__('Forward the developer report to your engineer'));
    }

    public function test_unlocked_email_links_both_reports(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $mailable = new AuditReportUnlocked($report, 'https://app.example.com/reports/abc?signature=x', 'https://app.example.com/reports/abc/technical?signature=y');

        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc/technical?signature=y');
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

    public function test_unlocked_email_attaches_both_pdfs(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 business');
        Storage::disk('local')->put('audit-reports/fixture-technical.pdf', '%PDF-1.4 technical');
        $report = AuditReport::factory()->unlocked()->create(['pdf_path' => 'audit-reports/fixture.pdf', 'technical_pdf_path' => 'audit-reports/fixture-technical.pdf']);

        $mailable = new AuditReportUnlocked($report, 'https://app.example.com/reports/abc?signature=x');

        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')->as('codebase-health-business.pdf')->withMime('application/pdf')
        );
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture-technical.pdf')->as('codebase-health-developer.pdf')->withMime('application/pdf')
        );
        $mailable->assertDontSeeInHtml('dashboard downloads');
    }

    /**
     * A mail queued before the split is unserialized without $technicalUrl:
     * a promoted property with no default stays uninitialized, and Mailable
     * skips uninitialized properties when it builds the view data.
     */
    public function test_report_emails_queued_before_the_split_still_render(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['pdf_path' => null]);

        $ready = (new \ReflectionClass(AuditReportReady::class))->newInstanceWithoutConstructor();
        $ready->report = $report;
        $ready->signedUrl = 'https://app.example.com/reports/abc?signature=x';
        $ready->deltas = null;
        $ready->groupDeltas = null;

        $unlocked = (new \ReflectionClass(AuditReportUnlocked::class))->newInstanceWithoutConstructor();
        $unlocked->report = $report;
        $unlocked->reportUrl = 'https://app.example.com/reports/abc?signature=x';

        $ready->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $unlocked->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
    }
}
