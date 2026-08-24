<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class TierProfileResolverTest extends FeatureTest
{
    /**
     * Diagnostic is the base tier and is sold as "the full analysis pipeline
     * plus AI interpretation" -- so it runs every scanner, in the committed
     * order. It carried a three-scanner subset while the Automated Health
     * Report sat above it; retiring that tier moved the full set down here.
     */
    public function test_diagnostic_runs_the_full_scanner_set_in_the_committed_order(): void
    {
        $profile = app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC);

        $this->assertSame(['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'], $profile->scanners);
        $this->assertTrue($profile->runsScanner('semgrep'));
        $this->assertTrue($profile->runsScanner('jscpd'));
    }

    /**
     * Deep review, not scanner count or token budget, is what separates the
     * tiers now. Asserting the budgets are equal is the guard against a
     * future edit quietly thinning Diagnostic back out.
     */
    public function test_every_tier_shares_the_diagnostic_scanner_and_token_budget(): void
    {
        $resolver = app(TierProfileResolver::class);
        $base = $resolver->for(AuditTier::DIAGNOSTIC);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT] as $tier) {
            $profile = $resolver->for($tier);

            $this->assertSame($base->scanners, $profile->scanners);
            $this->assertSame($base->excerptFiles, $profile->excerptFiles);
            $this->assertSame($base->aiMaxTokens, $profile->aiMaxTokens);
            $this->assertSame($base->narratedGroups, $profile->narratedGroups);
        }
    }

    public function test_every_tier_in_the_enumeration_resolves(): void
    {
        $resolver = app(TierProfileResolver::class);

        foreach (AuditTier::cases() as $tier) {
            $this->assertSame($tier, $resolver->for($tier)->tier);
        }
    }

    public function test_diagnostic_has_no_deep_review_profile(): void
    {
        $resolver = app(TierProfileResolver::class);

        $this->assertNull($resolver->for(AuditTier::DIAGNOSTIC)->deepReview);
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
