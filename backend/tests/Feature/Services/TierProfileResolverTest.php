<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class TierProfileResolverTest extends FeatureTest
{
    public function test_diagnostic_runs_only_the_cheap_scanner_subset(): void
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC);

        $this->assertSame(['scc', 'gitleaks', 'osv'], $profile->scanners);
        $this->assertFalse($profile->runsScanner('semgrep'));
        $this->assertFalse($profile->runsScanner('jscpd'));
    }

    public function test_automated_runs_the_full_set_in_the_committed_order(): void
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::AUTOMATED);

        $this->assertSame(['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'], $profile->scanners);
    }

    public function test_paid_tiers_have_larger_budgets_than_diagnostic(): void
    {
        $resolver = app(TierProfileResolver::class);
        $free = $resolver->for(AuditTier::DIAGNOSTIC);
        $paid = $resolver->for(AuditTier::AUTOMATED);

        $this->assertGreaterThan($free->excerptFiles, $paid->excerptFiles);
        $this->assertGreaterThan($free->aiMaxTokens, $paid->aiMaxTokens);
        $this->assertGreaterThan($free->narratedGroups, $paid->narratedGroups);
    }

    public function test_deep_ai_and_expert_share_the_automated_scanner_budget(): void
    {
        $resolver = app(TierProfileResolver::class);
        $automated = $resolver->for(AuditTier::AUTOMATED);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT] as $tier) {
            $this->assertSame($automated->scanners, $resolver->for($tier)->scanners);
        }
    }

    public function test_every_tier_in_the_enumeration_resolves(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach (AuditTier::cases() as $tier) {
            $this->assertSame($tier, $resolver->for($tier)->tier);
        }
    }

    public function test_diagnostic_and_automated_have_no_deep_review_profile(): void
    {
        $resolver = app(TierProfileResolver::class);

        $this->assertNull($resolver->for(AuditTier::DIAGNOSTIC)->deepReview);
        $this->assertNull($resolver->for(AuditTier::AUTOMATED)->deepReview);
    }

    public function test_deep_ai_and_expert_carry_a_deep_review_profile(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT] as $tier) {
            $profile = $resolver->for($tier)->deepReview;

            $this->assertNotNull($profile, "{$tier->value} must run deep review");
            $this->assertSame(20, $profile->minFiles);
            $this->assertSame(40, $profile->maxFiles);
            $this->assertSame(12000, $profile->fileBytes);
            $this->assertSame(4000, $profile->minFileBytes);
            $this->assertSame(150000, $profile->inputTokenBudget);
            $this->assertSame(16000, $profile->maxTokens);
        }
    }

    public function test_the_floor_is_reachable_within_the_budget(): void
    {
        // If min_files at min_file_bytes cannot fit, every deep run starts
        // below the floor — a configuration error, not a runtime condition.
        $profile = app(TierProfileResolver::class)->for(AuditTier::DEEP_AI)->deepReview;

        $floorTokens = (int) ceil(
            $profile->minFiles * $profile->minFileBytes / (float) config('audit.deep_review.chars_per_token')
        ) * config('audit.deep_review.safety_margin');

        $this->assertLessThan(
            $profile->inputTokenBudget - config('audit.deep_review.overhead_tokens'),
            $floorTokens,
        );
    }
}
