<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;
use Tests\Support\RunsAuditPipelineWithFakes;

class CostTelemetryTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuditPipelineFixture();
    }

    public function test_records_token_counts_from_the_analysis(): void
    {
        $request = $this->runPipelineWithFakes(inputTokens: 12_000, outputTokens: 3_400);

        $this->assertSame(12_000, $request->fresh()->ai_input_tokens);
        $this->assertSame(3_400, $request->fresh()->ai_output_tokens);
    }

    public function test_records_total_scanner_wall_time(): void
    {
        $request = $this->runPipelineWithFakes();

        $this->assertNotNull($request->fresh()->scanner_ms);
        $this->assertGreaterThanOrEqual(0, $request->fresh()->scanner_ms);
    }

    public function test_records_repository_size(): void
    {
        $request = $this->runPipelineWithFakes();

        $this->assertGreaterThan(0, $request->fresh()->repo_size_kb);
    }

    public function test_cost_per_tier_is_aggregable_in_one_query(): void
    {
        // This suite has no per-test DB reset, and other tests in this class
        // create their own AUTOMATED-tier rows via runPipelineWithFakes();
        // without this, their token counts pollute the average below.
        AuditRequest::query()->delete();

        // This is the shape Q5 needs on the first 20-30 paid runs.
        AuditRequest::factory()->count(3)->create([
            'tier' => AuditTier::AUTOMATED->value,
            'ai_input_tokens' => 10_000, 'ai_output_tokens' => 2_000,
            'scanner_ms' => 5_000, 'repo_size_kb' => 40_000,
        ]);
        AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'ai_input_tokens' => 2_000, 'ai_output_tokens' => 500,
            'scanner_ms' => 900, 'repo_size_kb' => 40_000,
        ]);

        $byTier = AuditRequest::query()
            ->whereNotNull('ai_input_tokens')
            ->groupBy('tier')
            ->selectRaw('tier, avg(ai_input_tokens) as avg_in, avg(ai_output_tokens) as avg_out, avg(scanner_ms) as avg_ms')
            ->pluck('avg_in', 'tier');

        $this->assertEquals(10_000, (int) $byTier[AuditTier::AUTOMATED->value]);
        $this->assertEquals(2_000, (int) $byTier[AuditTier::DIAGNOSTIC->value]);
    }

    public function test_telemetry_is_null_on_a_run_that_never_reached_analysis(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertNull($request->ai_input_tokens);
    }
}
