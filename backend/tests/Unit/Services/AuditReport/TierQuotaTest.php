<?php

namespace Tests\Unit\Services\AuditReport;

use App\Constants\AuditTier;
use App\Services\AuditReport\TierQuota;
use Tests\TestCase;

class TierQuotaTest extends TestCase
{
    private function quota(int $limit, int $used, ?int $priceCents = 19900): TierQuota
    {
        return new TierQuota(
            tier: AuditTier::DEEP_AI,
            label: 'Deep AI Code Review',
            limit: $limit,
            used: $used,
            isLifetime: false,
            priceCents: $priceCents,
        );
    }

    public function test_remaining_is_limit_minus_used(): void
    {
        $this->assertSame(3, $this->quota(5, 2)->remaining());
    }

    public function test_remaining_never_goes_negative(): void
    {
        // An operator-launched run can exceed the allowance; the quota must
        // report zero rather than a negative number the UI would render.
        $this->assertSame(0, $this->quota(1, 4)->remaining());
    }

    public function test_has_runs_reflects_remaining(): void
    {
        $this->assertTrue($this->quota(5, 2)->hasRuns());
        $this->assertFalse($this->quota(2, 2)->hasRuns());
    }

    public function test_a_tier_without_a_price_is_not_purchasable(): void
    {
        $this->assertTrue($this->quota(0, 0)->purchasable());
        $this->assertFalse($this->quota(0, 0, null)->purchasable());
    }

    public function test_every_tier_has_a_label(): void
    {
        foreach (AuditTier::cases() as $tier) {
            $this->assertNotSame('', $tier->label());
        }
    }

    public function test_every_tier_has_a_badge_colour(): void
    {
        foreach (AuditTier::cases() as $tier) {
            $this->assertNotSame('', $tier->badgeColor());
        }
    }

    public function test_no_tier_uses_the_colour_reserved_for_the_expert_review_hold(): void
    {
        // The list renders an "In expert review" warning badge alongside the
        // tier badge on the same row. A tier painted warning too would read as
        // one state rather than two.
        foreach (AuditTier::cases() as $tier) {
            $this->assertNotSame('warning', $tier->badgeColor(), $tier->value.' must not reuse the hold colour');
        }
    }

    public function test_tier_badge_colours_are_distinct(): void
    {
        $colours = array_map(fn (AuditTier $tier): string => $tier->badgeColor(), AuditTier::cases());

        $this->assertSame($colours, array_unique($colours), 'each tier must be distinguishable at a glance');
    }
}
