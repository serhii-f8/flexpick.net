<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Constants\ReportVariant;
use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportTechnicalPageTest extends FeatureTest
{
    private function technicalUrl(AuditReport $report): string
    {
        return app(AuditReportService::class)->signedUrl($report, ReportVariant::TECHNICAL);
    }

    public function test_the_developer_report_shows_every_technical_section(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $this->get($this->technicalUrl($report))
            ->assertOk()
            ->assertSee(__('Health scores'))
            ->assertSee(__('Risks, ranked by impact'))
            ->assertSee('Add a smoke suite')
            ->assertSee(__('What to fix first'));
    }

    public function test_the_developer_report_has_no_plain_terms_box(): void
    {
        $report = AuditReport::factory()->unlocked()->create([
            'payload' => AuditReport::factory()->definition()['payload'] + ['client_summary' => ['overview' => 'Owner-facing overview.', 'findings' => []]],
        ]);

        $this->get($this->technicalUrl($report))
            ->assertOk()
            ->assertDontSee(__('In plain terms'))
            ->assertDontSee('Owner-facing overview.');
    }

    public function test_the_developer_report_needs_a_signature(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->unlocked()->create();

        $this->get(route('reports.view.technical', $report))->assertForbidden();
    }

    public function test_the_developer_report_is_hidden_while_held_for_expert_review(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->unlocked()->create();
        $report->auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->get($this->technicalUrl($report))->assertForbidden();
    }

    public function test_tabs_link_both_reports_with_signed_urls(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $html = $this->get($this->technicalUrl($report))->assertOk()->getContent();

        $this->assertStringContainsString(__('Business overview'), $html);
        $this->assertStringContainsString(__('Developer report'), $html);
        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'\?[^"]*signature=#', $html);
        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'/technical\?[^"]*signature=#', $html);
    }

    public function test_the_sample_developer_report_is_public(): void
    {
        $this->get('/reports/sample/technical')
            ->assertOk()
            ->assertSee(__('Sample report'))
            ->assertSee(__('Deep file review'))
            ->assertDontSee(__('In plain terms'));
    }
}
