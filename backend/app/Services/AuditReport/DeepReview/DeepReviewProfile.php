<?php

namespace App\Services\AuditReport\DeepReview;

/**
 * The per-run budget for one tier's deep review.
 *
 * Separate from TierProfile because it is null for the tiers that do not run
 * deep review — which is exactly what AuditPipeline gates the stage on, so no
 * tier name is ever hardcoded in the pipeline.
 */
final readonly class DeepReviewProfile
{
    public function __construct(
        public int $minFiles,
        public int $maxFiles,
        public int $fileBytes,
        public int $minFileBytes,
        public int $inputTokenBudget,
        public int $maxTokens,
    ) {}
}
