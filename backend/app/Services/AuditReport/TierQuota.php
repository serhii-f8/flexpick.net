<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;

/**
 * One tier's quota position for one user: how many runs it allows, how many
 * are spent, and what buying another costs.
 *
 * Diagnostic is backed by the lifetime free-run quota and every other tier by
 * a monthly plan-metadata allowance. Carrying that difference as a flag here
 * is what lets the selector and PlanUsageWidget loop over tiers instead of
 * branching on which kind of quota backs each one.
 */
final readonly class TierQuota
{
    public function __construct(
        public AuditTier $tier,
        public string $label,
        public int $limit,
        public int $used,
        public bool $isLifetime,
        public ?int $priceCents,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function hasRuns(): bool
    {
        return $this->remaining() > 0;
    }

    /** Only tiers with a catalog price can be bought outright. */
    public function purchasable(): bool
    {
        return $this->priceCents !== null;
    }
}
