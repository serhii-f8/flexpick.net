<?php

namespace App\Services\AuditReport\Findings;

final readonly class DedupedFinding
{
    /**
     * @param  list<string>  $tools  contributing scanners, sorted
     */
    public function __construct(
        public Finding $finding,
        public array $tools,
    ) {}
}
