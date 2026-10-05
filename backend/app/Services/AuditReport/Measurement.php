<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;

final readonly class Measurement
{
    /**
     * @param  list<FindingGroup>  $groups
     * @param  array<string, mixed>  $metrics
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function __construct(
        public ScannerSuiteResult $suite,
        public array $groups,
        public array $metrics,
        public array $excerpts,
        public ScoreSet $scores,
    ) {}
}
