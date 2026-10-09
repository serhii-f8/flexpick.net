<?php

namespace Tests\Feature\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Services\AuditReport\BusinessReportPresenter;
use App\Support\ScoreBand;
use Tests\Feature\FeatureTest;

class BusinessReportPresenterTest extends FeatureTest
{
    private function v6Payload(): array
    {
        return AuditReport::factory()->definition()['payload'] + [
            'groups' => [['rule_family' => 'php:unused', 'directory' => 'app', 'severity' => 'medium', 'count' => 4, 'narrative' => ['what' => 'w', 'affects' => 'a', 'benefit' => 'b']]],
            'client_summary' => [
                'overview' => 'Overview.',
                'verdict' => 'Verdict.',
                'areas' => [['area' => 'testing', 'meaning' => 'Checks.', 'status' => 'Few.']],
                'findings' => [
                    ['what' => 'A', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'now', 'business_area' => 'customers'],
                    ['what' => 'B', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'later', 'business_area' => 'customers'],
                ],
                'roadmap' => [['step' => 'S1', 'outcome' => 'O1', 'effort' => 'L']],
                'questions' => ['Q1?'],
            ],
        ];
    }

    private function present(AuditReport $report): array
    {
        return app(BusinessReportPresenter::class)->present($report, null, 40, $report->auditRequest->findingGroups);
    }

    public function test_presents_a_v6_report(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $view = $this->present($report);

        $this->assertSame(55, $view['overall']);
        $this->assertSame(ScoreBand::WATCH, $view['band']);
        $this->assertSame('Verdict.', $view['verdict']);
        $this->assertTrue($view['hasClientSummary']);
        $this->assertSame('now', $view['findings'][0]['urgency']);
        $this->assertSame(__('Fix now'), $view['findings'][0]['urgency_label']);
        $this->assertSame(__('A month or more'), $view['roadmap'][0]['effort_label']);
        $this->assertSame(['Q1?'], $view['questions']);
        $this->assertNotNull($view['charts']['businessAreas']);
        $this->assertNotNull($view['charts']['percentile']);

        $testing = collect($view['areas'])->firstWhere('key', 'testing');
        $this->assertSame(20, $testing['score']);
        $this->assertSame('Checks.', $testing['meaning']);
        $this->assertNull(collect($view['areas'])->firstWhere('key', 'structure')['meaning']);
    }

    public function test_severity_counts_come_from_the_stored_finding_groups(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);
        AuditFindingGroup::factory()->create(['audit_request_id' => $report->audit_request_id, 'severity' => 'critical', 'count' => 7]);

        $view = $this->present($report->fresh());

        $this->assertStringContainsString(__('Urgent').' 1', $view['charts']['severity']);
    }

    public function test_severity_counts_fall_back_to_the_payload_groups(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $view = $this->present($report);

        $this->assertStringContainsString(__('Important').' 1', $view['charts']['severity']);
    }

    public function test_a_v5_summary_has_no_roadmap_questions_or_business_area_chart(): void
    {
        $payload = $this->v6Payload();
        $payload['client_summary'] = ['overview' => 'Old.', 'findings' => [['what' => 'A', 'consequence' => 'c', 'gain' => 'g']]];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $view = $this->present($report);

        $this->assertNull($view['verdict']);
        $this->assertSame('Old.', $view['overview']);
        $this->assertNull($view['findings'][0]['urgency']);
        $this->assertNull($view['roadmap']);
        $this->assertNull($view['questions']);
        $this->assertNull($view['charts']['businessAreas']);
    }

    public function test_a_report_without_a_client_summary(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $view = $this->present($report);

        $this->assertFalse($view['hasClientSummary']);
        $this->assertNull($view['overview']);
        $this->assertSame([], $view['findings']);
        $this->assertNotSame('', $view['charts']['gauge']);
    }

    public function test_unmeasured_dimensions_are_listed_without_a_score(): void
    {
        $payload = $this->v6Payload();
        unset($payload['scores']['testing']);
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);
        $report->auditRequest->update(['metrics' => ['not_measured' => ['testing']]]);

        $view = $this->present($report->fresh());

        $this->assertNull(collect($view['areas'])->firstWhere('key', 'testing')['score']);
    }

    public function test_expert_summary_is_exposed_without_the_review_notes(): void
    {
        $payload = $this->v6Payload() + ['expert_review' => ['expert_summary' => 'Looks fixable.', 'review_notes' => 'Internal.', 'reviewed_by' => 'R', 'reviewed_at' => '2026-10-01T00:00:00Z']];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $view = $this->present($report);

        $this->assertSame('Looks fixable.', $view['expertSummary']);
        $this->assertStringNotContainsString('Internal.', json_encode($view));
    }
}
