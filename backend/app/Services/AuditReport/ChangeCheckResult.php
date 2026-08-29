<?php

namespace App\Services\AuditReport;

final readonly class ChangeCheckResult
{
    public function __construct(
        public bool $shouldRun,
        public ?string $sha,
    ) {}
}
