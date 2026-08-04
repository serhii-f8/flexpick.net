<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Exceptions\AiAnalysisException;
use App\Notifications\OperationsAlert;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeDeepReviewer;
use Tests\Support\RunsAuditPipelineWithFakes;

class DeepReviewPipelineTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAuditPipelineFixture();
        Notification::fake();
    }

    private function finding(string $path = 'app/Auth/Guard.php'): array
    {
        return [
            'path' => $path,
            'line' => 2,
            'title' => 'Guard allows null roles',
            'severity' => 'critical',
            'category' => 'authorization',
            'evidence' => 'class Guard has no role check',
            'recommendation' => 'Deny by default.',
            'effort' => 'M',
            'related_paths' => [],
        ];
    }

    public function test_a_deep_run_produces_file_findings_and_telemetry(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [$this->finding()]),
        );

        $payload = $request->report->payload;

        $this->assertCount(1, $payload['file_findings']);
        $this->assertSame('app/Auth/Guard.php', $payload['file_findings'][0]['path']);
        $this->assertFalse($payload['deep_review']['degraded']);
        $this->assertSame(2000, $request->deep_review_input_tokens);
        $this->assertSame(400, $request->deep_review_output_tokens);
        $this->assertNotNull($request->risk_files);
        $this->assertSame(1, $request->risk_files['selection_version']);
    }

    public function test_an_automated_run_never_calls_the_deep_reviewer(): void
    {
        // A silent regression here would bill tier-2 costs against tier-1
        // revenue, so the gate itself is asserted.
        $reviewer = new FakeDeepReviewer(findings: [$this->finding()]);

        $request = $this->runPipelineWithFakes(
            tier: AuditTier::AUTOMATED,
            deepReviewer: $reviewer,
        );

        $this->assertNull($reviewer->receivedSelection);
        $this->assertArrayNotHasKey('file_findings', $request->report->payload);
        $this->assertNull($request->deep_review_input_tokens);
    }

    public function test_the_expert_tier_also_runs_deep_review(): void
    {
        $reviewer = new FakeDeepReviewer(findings: [$this->finding()]);

        $this->runPipelineWithFakes(tier: AuditTier::EXPERT, deepReviewer: $reviewer);

        $this->assertNotNull($reviewer->receivedSelection);
    }

    public function test_a_failed_deep_review_still_delivers_a_complete_report(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(throws: new AiAnalysisException('deep boom')),
        );

        $payload = $request->report->payload;

        $this->assertNotNull($request->report);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertTrue($payload['deep_review']['degraded']);
        $this->assertArrayNotHasKey('file_findings', $payload);

        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    public function test_zero_findings_is_not_degradation(): void
    {
        // P6: a healthy verdict is a designed outcome, not an empty state.
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: []),
        );

        $payload = $request->report->payload;

        $this->assertFalse($payload['deep_review']['degraded']);
        $this->assertSame([], $payload['file_findings']);
        Notification::assertNothingSent();
    }

    public function test_fabricated_findings_are_dropped(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [$this->finding('app/Never/Sent.php')]),
        );

        $payload = $request->report->payload;

        // Every finding was fabricated, so the review is treated as degraded.
        $this->assertTrue($payload['deep_review']['degraded']);
        Notification::assertSentOnDemand(OperationsAlert::class);
    }

    public function test_the_deep_reviewer_receives_deterministic_context_only(): void
    {
        $reviewer = new FakeDeepReviewer(findings: []);

        $this->runPipelineWithFakes(tier: AuditTier::DEEP_AI, deepReviewer: $reviewer);

        $this->assertIsArray($reviewer->receivedMetrics);
        $this->assertIsArray($reviewer->receivedGroups);
        $this->assertNotNull($reviewer->receivedSelection);
    }
}
