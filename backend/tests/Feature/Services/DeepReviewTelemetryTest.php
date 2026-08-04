<?php

namespace Tests\Feature\Services;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class DeepReviewTelemetryTest extends FeatureTest
{
    public function test_deep_review_columns_round_trip(): void
    {
        $request = AuditRequest::factory()->create();

        $request->update([
            'risk_files' => ['selection_version' => 1, 'files' => [['path' => 'app/A.php', 'rank' => 1]]],
            'deep_review_input_tokens' => 91_000,
            'deep_review_output_tokens' => 4_200,
            'deep_review_ms' => 38_000,
        ]);

        $fresh = $request->fresh();

        $this->assertSame(1, $fresh->risk_files['selection_version']);
        $this->assertSame('app/A.php', $fresh->risk_files['files'][0]['path']);
        $this->assertSame(91_000, $fresh->deep_review_input_tokens);
        $this->assertSame(4_200, $fresh->deep_review_output_tokens);
        $this->assertSame(38_000, $fresh->deep_review_ms);
    }

    public function test_tier_one_and_tier_two_token_counts_are_independent(): void
    {
        $request = AuditRequest::factory()->create([
            'ai_input_tokens' => 12_000,
            'deep_review_input_tokens' => 91_000,
        ]);

        $this->assertSame(12_000, $request->fresh()->ai_input_tokens);
        $this->assertSame(91_000, $request->fresh()->deep_review_input_tokens);
    }
}
