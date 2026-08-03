<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Findings\Finding;

/**
 * An external static analyzer.
 *
 * Distinct from Collector: a Scanner throwing is NORMAL and is absorbed by
 * ScannerRunner. A Collector throwing is a bug and fails the run. One
 * interface would force one policy, and the absorbing policy is wrong for
 * internal code (spec §3.1).
 */
interface Scanner
{
    public function name(): string;

    /** Whether the configured binary exists and is executable. */
    public function isAvailable(): bool;

    /** The configured, pinned version — recorded per run as provenance. */
    public function version(): string;

    /** @return list<Finding> */
    public function scan(RepoContext $context): array;
}
