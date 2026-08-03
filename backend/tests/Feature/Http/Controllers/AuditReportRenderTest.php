<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditTier;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportRenderTest extends FeatureTest
{
    private function reportWith(array $payload, array $metrics = [], AuditTier $tier = AuditTier::AUTOMATED): AuditReport
    {
        $request = AuditRequest::factory()->create(['tier' => $tier->value, 'metrics' => $metrics]);

        return AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => $payload,
            'payload_schema_version' => 2,
            'scoring_version' => 2,
            'unlocked_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'summary' => 'Serviceable but under-tested.',
            'scores' => ['structure' => 70, 'testing' => 40, 'overall' => 60],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [
                ['rule_family' => 'php.injection', 'directory' => 'app/Http',
                 'severity' => 'high', 'count' => 37,
                 'narrative' => ['what' => 'SQL by string interpolation.',
                                 'affects' => 'Public controllers.',
                                 'benefit' => 'Closes the main exfiltration route.']],
            ],
        ], $overrides);
    }

    public function test_renders_group_narration(): void
    {
        $report = $this->reportWith($this->payload());

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertSee('SQL by string interpolation.')
            ->assertSee('Public controllers.')
            ->assertSee('Closes the main exfiltration route.');
    }

    public function test_shows_the_finding_count_not_a_finding_list(): void
    {
        $report = $this->reportWith($this->payload());

        $this->get(app(AuditReportService::class)->signedUrl($report))->assertSee('37');
    }

    public function test_marks_unmeasured_dimensions_rather_than_scoring_them(): void
    {
        // The customer must never see a score the run did not earn (spec §7.2).
        $report = $this->reportWith(
            $this->payload(),
            ['not_measured' => ['duplication', 'security_hygiene']],
        );

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertSee(__('Not measured'));
    }

    public function test_a_historical_v1_report_still_renders(): void
    {
        $request = AuditRequest::factory()->create(['tier' => 'diagnostic']);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload_schema_version' => 1,
            'scoring_version' => 1,
            'unlocked_at' => now(),
            'payload' => [
                'summary' => 'Legacy report.',
                'scores' => ['structure' => 70, 'duplication' => 60, 'testing' => 40,
                             'dependencies' => 80, 'security_hygiene' => 90, 'overall' => 68],
                'risks' => [], 'fix_first_plan' => [],
            ],
        ]);

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertSee('Legacy report.');
    }

    public function test_a_locked_diagnostic_report_shows_only_the_teaser_groups(): void
    {
        $payload = $this->payload();
        $payload['groups'][] = ['rule_family' => 'duplication.clone', 'directory' => 'app',
            'severity' => 'medium', 'count' => 12,
            'narrative' => ['what' => 'Duplicated blocks.', 'affects' => 'Maintenance cost.',
                            'benefit' => 'Cheaper changes.']];

        $request = AuditRequest::factory()->create(['tier' => 'diagnostic']);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => $payload,
            'payload_schema_version' => 2,
            'scoring_version' => 2,
            'unlocked_at' => null,
        ]);

        $this->get(app(AuditReportService::class)->signedUrl($report))
            ->assertOk()
            ->assertDontSee('Cheaper changes.');
    }
}
