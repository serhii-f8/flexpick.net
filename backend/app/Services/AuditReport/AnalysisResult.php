<?php

namespace App\Services\AuditReport;

/**
 * The analysis payload plus its direct cost drivers.
 *
 * Token counts exist here because F5.12.6 requires cost per audit to be
 * measurable per tier from the first paid runs, and the model call is the
 * dominant cost. Returning a bare array made that impossible.
 */
final readonly class AnalysisResult
{
    public function __construct(
        public array $payload,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
