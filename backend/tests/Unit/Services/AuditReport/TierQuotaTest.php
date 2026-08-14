<?php

namespace Tests\Unit\Services\AuditReport;

use App\Constants\AuditTier;
use App\Services\AuditReport\TierQuota;
use PHPUnit\Framework\TestCase;

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
}
