<?php

namespace Tests\Feature\Services;

use App\Constants\AuditAiStage;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditAiCall;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeDeepReviewer;
use Tests\Support\RunsAuditPipelineWithFakes;

/**
 * The per-call spend ledger.
 *
 * Every test here exists because the request row alone could not answer "what
 * did we spend?": it holds one set of token columns, written after delivery.
 * On 2026-08-14 that recorded $0.75 of $10.27 actually billed — eleven model
 * calls ran, retries re-billed the full pipeline, and one row survived.
 */
class AuditAiCallLedgerTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->setUpAuditPipelineFixture();
    }

    public function test_a_tier_one_call_is_recorded_with_its_tokens_and_model(): void
    {
        $request = $this->runPipelineWithFakes(inputTokens: 24_511, outputTokens: 2_536);

        $call = $request->aiCalls()->sole();

        $this->assertSame(AuditAiStage::ANALYSIS, $call->stage);
        $this->assertSame(AuditAiCall::OUTCOME_OK, $call->outcome);
        $this->assertSame(24_511, $call->input_tokens);
        $this->assertSame(2_536, $call->output_tokens);
        $this->assertSame(config('services.anthropic.model'), $call->model);
        $this->assertNotNull($call->duration_ms);
    }

    /**
     * The bug this whole table exists for: the call is billed when it returns,
     * so spend must be durable before the report is rendered or mailed.
     */
    public function test_spend_is_recorded_even_when_delivery_fails(): void
    {
        $this->mock(AuditReportService::class)
            ->shouldReceive('createAndDeliver')
            ->andThrow(new RuntimeException('Unable to JSON encode payload'));

        try {
            $this->runPipelineWithFakes(inputTokens: 133_701, outputTokens: 3_314);
            $this->fail('The delivery failure should have propagated to the queue.');
        } catch (RuntimeException) {
            // Expected: the job fails and Horizon retries it, re-billing the run.
        }

        // The run threw before handing the request back, and this suite does
        // not roll back between tests — so reach for the row this test made.
        $request = AuditRequest::query()->latest('id')->firstOrFail();

        $call = $request->aiCalls()->sole();
        $this->assertSame(133_701, $call->input_tokens);
        $this->assertSame(3_314, $call->output_tokens);

        // The legacy columns must carry the same figures, so existing
        // per-request reporting stops under-counting too.
        $this->assertSame(133_701, $request->ai_input_tokens);
        $this->assertSame(3_314, $request->ai_output_tokens);

        // ...but the run is not complete, and must not look it.
        $this->assertNull($request->analysis_completed_at);
    }

    public function test_a_rerun_appends_a_second_call_rather_than_overwriting_the_first(): void
    {
        $request = $this->runPipelineWithFakes(inputTokens: 133_701, outputTokens: 3_314);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $calls = $request->fresh()->aiCalls;

        $this->assertCount(2, $calls);
        $this->assertSame(267_402, $calls->sum('input_tokens'));

        // The request columns still describe only the latest attempt — which
        // is precisely why the ledger is the answer to "what did we spend".
        $this->assertSame(133_701, $request->fresh()->ai_input_tokens);
    }

    public function test_a_deep_review_is_recorded_even_when_its_findings_are_rejected(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(findings: [[
                'path' => 'app/Never/Sent.php',
                'line' => 2,
                'title' => 'Fabricated finding',
                'severity' => 'critical',
                'category' => 'authorization',
                'evidence' => 'never sent to the model',
                'recommendation' => 'Deny by default.',
                'effort' => 'M',
                'related_paths' => [],
            ]]),
        );

        // Every finding was fabricated, so the section degrades...
        $this->assertTrue($request->report->payload['deep_review']['degraded']);

        // ...but the call that produced them was still billed.
        $deep = $request->aiCalls()->where('stage', AuditAiStage::DEEP_REVIEW->value)->sole();
        $this->assertSame(AuditAiCall::OUTCOME_OK, $deep->outcome);
        $this->assertSame(2_000, $deep->input_tokens);
        $this->assertSame(400, $deep->output_tokens);
    }

    public function test_a_reviewer_that_never_returns_is_recorded_as_an_unsized_call(): void
    {
        $request = $this->runPipelineWithFakes(
            tier: AuditTier::DEEP_AI,
            deepReviewer: new FakeDeepReviewer(throws: new RuntimeException('idle timeout')),
        );

        $deep = $request->aiCalls()->where('stage', AuditAiStage::DEEP_REVIEW->value)->sole();

        $this->assertSame(AuditAiCall::OUTCOME_FAILED, $deep->outcome);
        $this->assertSame(RuntimeException::class, $deep->failure_reason);
        // Unknown, not zero: a client-side timeout is billed in full.
        $this->assertNull($deep->input_tokens);
        $this->assertNull($deep->costUsd());
    }

    public function test_a_selection_failure_is_not_recorded_as_a_model_call(): void
    {
        // No deep reviewer is invoked when selection yields nothing, so the
        // ledger must not invent spend for the stage.
        $request = $this->runPipelineWithFakes(tier: AuditTier::AUTOMATED);

        $this->assertCount(0, $request->aiCalls()->where('stage', AuditAiStage::DEEP_REVIEW->value)->get());
    }

    public function test_cost_uses_configured_list_prices(): void
    {
        config(['audit.model_pricing.test-model' => ['input' => 5.0, 'output' => 25.0]]);

        $call = AuditAiCall::factory()->for(AuditRequest::factory())->create([
            'model' => 'test-model',
            'input_tokens' => 133_701,
            'output_tokens' => 3_314,
        ]);

        // 133,701 × $5/M + 3,314 × $25/M
        $this->assertEqualsWithDelta(0.751355, $call->costUsd(), 0.000001);
    }

    public function test_an_unpriced_model_costs_unknown_rather_than_nothing(): void
    {
        $call = AuditAiCall::factory()->for(AuditRequest::factory())->create([
            'model' => 'some-model-nobody-priced',
        ]);

        $this->assertNull($call->costUsd());
        $this->assertTrue(AuditAiCall::unpriced()->whereKey($call->id)->exists());
    }
}
