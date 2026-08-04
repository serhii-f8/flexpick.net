<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * File-bound findings plus this stage's own cost drivers.
 *
 * Token counts are kept separate from AnalysisResult's so the MARGINAL cost of
 * tier 2 stays measurable — summing them would make F5.12.6's question ("what
 * does a $199 report cost us?") unanswerable.
 */
final readonly class DeepReviewResult
{
    /** @param list<array<string, mixed>> $findings */
    public function __construct(
        public array $findings,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
