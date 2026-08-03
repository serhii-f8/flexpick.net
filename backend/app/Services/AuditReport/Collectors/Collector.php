<?php

namespace App\Services\AuditReport\Collectors;

use App\Services\AuditReport\Scanners\RepoContext;

/**
 * An internal metric probe.
 *
 * Distinct from Scanner: a Collector throwing is a BUG and fails the run.
 * A Scanner throwing is normal and is absorbed. One interface would force
 * one policy, and absorbing is wrong for internal code (spec §3.1).
 */
interface Collector
{
    /** Key under which this collector's output is merged into metrics. */
    public function name(): string;

    /** @return array<string, mixed> */
    public function collect(RepoContext $context): array;
}
