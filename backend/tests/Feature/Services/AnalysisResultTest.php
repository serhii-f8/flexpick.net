<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AnalysisResult;
use App\Services\AuditReport\Tiers\TierProfile;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use ReflectionNamedType;
use Tests\Feature\FeatureTest;

class AnalysisResultTest extends FeatureTest
{
    public function test_carries_the_payload_and_both_token_counts(): void
    {
        $result = new AnalysisResult(['summary' => 'ok'], inputTokens: 1200, outputTokens: 800);

        $this->assertSame(['summary' => 'ok'], $result->payload);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(800, $result->outputTokens);
    }

    public function test_the_analyzer_interface_returns_an_analysis_result(): void
    {
        $returnType = (new \ReflectionMethod(AiAnalyzer::class, 'analyze'))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(AnalysisResult::class, $returnType->getName());
    }

    public function test_the_analyzer_interface_accepts_groups_and_a_tier_profile(): void
    {
        $parameters = (new \ReflectionMethod(AiAnalyzer::class, 'analyze'))->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $parameters);

        $this->assertSame(['metrics', 'groups', 'excerpts', 'tier', 'adminContext'], $names);
        $this->assertSame(TierProfile::class, $parameters[3]->getType()->getName());
    }

    public function test_a_fake_analyzer_can_satisfy_the_interface(): void
    {
        $fake = new class implements AiAnalyzer
        {
            public function analyze(array $metrics, array $groups, array $excerpts,
                TierProfile $tier, ?string $adminContext = null): AnalysisResult
            {
                return new AnalysisResult(['summary' => 'faked'], 10, 20);
            }
        };

        $result = $fake->analyze([], [], [], app(TierProfileResolver::class)->for(AuditTier::AUTOMATED));

        $this->assertSame('faked', $result->payload['summary']);
    }
}
