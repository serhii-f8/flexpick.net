<?php

namespace App\Services\AuditReport\DeepReview;

use App\Services\AuditReport\Findings\FindingGroup;

/**
 * An interface with a separate implementation for the same reason AiAnalyzer
 * has one: risk T1 (a provider change must not stop the pipeline), and so the
 * stage is fakeable without a network call.
 */
interface DeepReviewer
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups  ranked, already capped to the tier budget
     */
    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult;
}
