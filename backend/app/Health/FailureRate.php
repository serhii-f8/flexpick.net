<?php

namespace App\Health;

/**
 * A failure share over a window, with a minimum-sample floor.
 *
 * Below the floor the rate is not meaningful: one failure on a quiet day
 * would otherwise read as 100% and page someone. Mirrors the
 * benchmark_min_sample rule in config/audit.php.
 */
final readonly class FailureRate
{
    public function __construct(
        public int $total,
        public int $failed,
        public int $minSamples,
    ) {}

    public function belowFloor(): bool
    {
        return $this->total < $this->minSamples;
    }

    public function percent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round($this->failed / $this->total * 100);
    }
}
