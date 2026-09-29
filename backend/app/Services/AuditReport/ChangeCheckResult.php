<?php

namespace App\Services\AuditReport;

final readonly class ChangeCheckResult
{
    public function __construct(
        public bool $shouldRun,
        public ?string $sha,
        /** The remote could not be checked right now (transient git outage): skip this cycle. */
        public bool $unavailable = false,
    ) {}
}
