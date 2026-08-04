<?php

namespace Tests\Feature\Http;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class DeepReviewRenderingTest extends FeatureTest
{
    private function reportWith(array $deepKeys): AuditReport
    {
        $request = AuditRequest::factory()->create();

        return AuditReport::create(array_merge([
            'audit_request_id' => $request->id,
            'payload' => array_merge([
                'summary' => 'A summary.',
                'scores' => ['overall' => 50],
                'risks' => [],
                'fix_first_plan' => [],
                'groups' => [],
            ], $deepKeys),
            'unlocked_at' => now(),
            'scoring_version' => 1,
            'payload_schema_version' => 3,
        ]));
    }

    private function finding(array $overrides = []): array
    {
        return array_merge([
            'path' => 'app/Auth/Guard.php',
            'line' => 42,
            'title' => 'Authorization can be bypassed',
            'severity' => 'critical',
            'category' => 'authorization',
            'evidence' => 'The guard returns true when the role is null.',
            'recommendation' => 'Deny by default.',
            'effort' => 'M',
            'related_paths' => ['app/Services/Billing.php'],
        ], $overrides);
    }

    private function renderReport(AuditReport $report): string
    {
        return $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->getContent();
    }

    public function test_file_findings_render_grouped_by_file(): void
    {
        $html = $this->renderReport($this->reportWith([
            'file_findings' => [$this->finding()],
            'deep_review' => ['files_selected' => 40, 'files_reviewed' => 40, 'truncated' => false, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('app/Auth/Guard.php', $html);
        $this->assertStringContainsString('Authorization can be bypassed', $html);
        $this->assertStringContainsString('Deny by default.', $html);
        $this->assertStringContainsString('app/Services/Billing.php', $html);
    }

    public function test_truncation_is_disclosed(): void
    {
        $html = $this->renderReport($this->reportWith([
            'file_findings' => [$this->finding()],
            'deep_review' => ['files_selected' => 40, 'files_reviewed' => 28, 'truncated' => true, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('28', $html);
        $this->assertStringContainsString('40', $html);
    }

    public function test_degradation_is_disclosed(): void
    {
        $html = $this->renderReport($this->reportWith([
            'deep_review' => ['files_selected' => 0, 'files_reviewed' => 0, 'truncated' => false, 'selection_version' => 1, 'degraded' => true],
        ]));

        $this->assertStringContainsString('could not be completed', $html);
    }

    public function test_zero_findings_renders_a_confident_healthy_verdict(): void
    {
        $html = $this->renderReport($this->reportWith([
            'file_findings' => [],
            'deep_review' => ['files_selected' => 30, 'files_reviewed' => 30, 'truncated' => false, 'selection_version' => 1, 'degraded' => false],
        ]));

        $this->assertStringContainsString('No file-level issues', $html);
        $this->assertStringNotContainsString('could not be completed', $html);
    }

    public function test_a_report_with_no_deep_section_renders_unchanged(): void
    {
        $html = $this->renderReport($this->reportWith([]));

        $this->assertStringNotContainsString('Deep file review', $html);
    }
}
