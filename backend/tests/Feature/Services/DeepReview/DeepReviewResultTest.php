<?php

namespace Tests\Feature\Services\DeepReview;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewResult;
use App\Services\AuditReport\DeepReview\RiskFileSelection;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeDeepReviewer;

class DeepReviewResultTest extends FeatureTest
{
    public function test_the_container_resolves_a_deep_reviewer(): void
    {
        $this->assertInstanceOf(DeepReviewer::class, app(DeepReviewer::class));
    }

    public function test_the_result_carries_findings_and_token_counts(): void
    {
        $result = new DeepReviewResult(
            findings: [['path' => 'app/Auth/Guard.php', 'title' => 'Bypass']],
            inputTokens: 1200,
            outputTokens: 340,
        );

        $this->assertCount(1, $result->findings);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(340, $result->outputTokens);
    }

    public function test_the_fake_can_be_configured_to_throw(): void
    {
        $this->expectException(\RuntimeException::class);

        (new FakeDeepReviewer(throws: new \RuntimeException('boom')))
            ->review([], [], $this->emptySelection(), $this->profile());
    }

    private function emptySelection()
    {
        return new RiskFileSelection(
            files: [], candidatesConsidered: 0, selectedBeforeBudget: 0,
            truncated: false, belowFloor: true, estimatedInputTokens: 0,
            fileBytesUsed: 12000, selectionVersion: 1,
        );
    }

    private function profile()
    {
        return app(TierProfileResolver::class)
            ->for(AuditTier::DEEP_AI)->deepReview;
    }
}
