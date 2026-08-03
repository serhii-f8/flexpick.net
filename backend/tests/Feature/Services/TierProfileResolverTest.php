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

    public function test_deep_ai_and_expert_match_automated_in_this_phase(): void
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
}
