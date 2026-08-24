<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Exceptions\AiAnalysisException;
use App\Notifications\OperationsAlert;
use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
use App\Services\AuditReport\DeepReview\DeepReviewResult;
use App\Services\AuditReport\DeepReview\RiskFileSelection;
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

    public function test_a_diagnostic_run_never_calls_the_deep_reviewer(): void
    {
        // A silent regression here would bill tier-2 costs against tier-1
        // revenue, so the gate itself is asserted.
        $reviewer = new FakeDeepReviewer(findings: [$this->finding()]);

        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DIAGNOSTIC,
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

    public function test_an_empty_selection_is_degraded_and_never_reaches_the_reviewer(): void
    {
        // Finding 1: every candidate excluded (or an empty inventory) must
        // not read as "reviewed and clean." The reviewer is instrumented to
        // record whether it was ever called at all — proving the pipeline
        // short-circuits BEFORE reaching it, not merely that a subsequent
        // exception was caught and degraded the same way.
        config(['audit.deep_review.path_exclusions' => ['*']]);

        $reviewer = new class implements DeepReviewer
        {
            public int $calls = 0;

            public function review(
                array $metrics,
                array $groups,
                RiskFileSelection $selection,
                DeepReviewProfile $profile,
            ): DeepReviewResult {
                $this->calls++;

                throw new \RuntimeException('review() must not be called when selection is empty');
            }
        };

        $request = $this->runPipelineWithFakes(tier: AuditTier::DEEP_AI, deepReviewer: $reviewer);

        $payload = $request->report->payload;

        $this->assertSame(0, $reviewer->calls);
        $this->assertTrue($payload['deep_review']['degraded']);
        $this->assertSame(0, $payload['deep_review']['files_reviewed']);
        $this->assertArrayNotHasKey('file_findings', $payload);
    }

    public function test_a_malformed_finding_degrades_the_section_instead_of_persisting(): void
    {
        // Finding 2: ReportPayload::validate() never ran against the merged
        // v3 payload (file_findings/deep_review), so a malformed finding from
        // the model — or a sanitizer bug — could persist permanently and
        // break Blade rendering (e.g. badge-{severity} with no matching CSS
        // class). DeepFindingSanitizer only checks path membership, so this
        // finding sails through it untouched; validation must be what catches it.
        $badFinding = $this->finding();
        $badFinding['severity'] = 'nonsense';

        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [$badFinding]),
        );

        $payload = $request->report->payload;

        $this->assertTrue($payload['deep_review']['degraded']);
        $this->assertArrayNotHasKey('file_findings', $payload);
        Notification::assertSentOnDemand(OperationsAlert::class);
    }
}
