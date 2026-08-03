<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;

final readonly class ScannerSuiteResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<ScannerRun>  $runs
     */
    public function __construct(
        public array $findings,
        public array $runs,
    ) {}

    /**
     * Whether the named scanner ran to completion. A scanner that was never
     * asked to run returns false — the score dimensions it feeds must be
     * marked not-measured either way (spec §7.2).
     */
    public function ranSuccessfully(string $scanner): bool
    {
        foreach ($this->runs as $run) {
            if ($run->name === $scanner) {
                return $run->outcome === ScannerOutcome::OK;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function runsAsArray(): array
    {
        return array_map(fn (ScannerRun $run): array => $run->toArray(), $this->runs);
    }

    public function totalWallMs(): int
    {
        return array_sum(array_map(fn (ScannerRun $run): int => $run->wallMs, $this->runs));
    }
}
