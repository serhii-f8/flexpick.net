<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;

/**
 * What one tier cost over a window, and how much of that figure is trustworthy.
 *
 * `unsizedCalls` travels with the money on purpose. A call that timed out, or
 * ran on a model with no configured price, is spend we know happened and cannot
 * size — folding it in as zero is how a total quietly under-reports, which is
 * the exact failure this whole ledger exists to stop.
 */
final readonly class TierSpend
{
    public function __construct(
        public AuditTier $tier,
        public float $spendUsd,
        public int $calls,
        public int $unsizedCalls,
        public int $reports,
    ) {}

    /** Null when nothing was delivered — an average over zero reports is not 0. */
    public function costPerReport(): ?float
    {
        return $this->reports > 0 ? $this->spendUsd / $this->reports : null;
    }

    public function isComplete(): bool
    {
        return $this->unsizedCalls === 0;
    }
}
