<?php

namespace App\Services\AuditReport\Scanners;

use RuntimeException;

/**
 * A scanner ran but measured nothing it could stand behind — no report file,
 * an unreachable advisory database, no readable lockfile. Thrown instead of
 * returning [] so the dimension is reported "not measured" rather than scored
 * as a clean repository.
 *
 * `reason` is a fixed code and reaches the pipeline log and the report; it
 * must never carry tool output (spec §5.4).
 */
final class ScannerSkipped extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
