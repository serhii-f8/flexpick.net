<?php

namespace App\Services\AuditReport;

/**
 * Deterministic scores plus an explicit record of what could NOT be measured.
 *
 * A dimension whose contributing scanner did not run is absent from `scores`
 * and named in `notMeasured` — never scored, never counted toward `overall`.
 * Zero findings from a scanner that never ran is not a clean repository
 * (spec §7.2).
 */
final readonly class ScoreSet
{
    /**
     * @param  array<string, int>  $scores       measured dimensions plus `overall`
     * @param  list<string>  $notMeasured        dimension names, sorted
     */
    public function __construct(
        public array $scores,
        public array $notMeasured,
        public int $scoringVersion,
    ) {}

    /** @return array<string, int> */
    public function toPayloadScores(): array
    {
        return $this->scores;
    }

    public function wasMeasured(string $dimension): bool
    {
        return ! in_array($dimension, $this->notMeasured, true);
    }
}
