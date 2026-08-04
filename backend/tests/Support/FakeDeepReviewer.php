<?php

namespace Tests\Support;

use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
use App\Services\AuditReport\DeepReview\DeepReviewResult;
use App\Services\AuditReport\DeepReview\RiskFileSelection;
use Throwable;

class FakeDeepReviewer implements DeepReviewer
{
    public ?RiskFileSelection $receivedSelection = null;

    public ?array $receivedMetrics = null;

    public ?array $receivedGroups = null;

    /** @param list<array<string, mixed>> $findings */
    public function __construct(
        public array $findings = [],
        public ?Throwable $throws = null,
    ) {}

    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;
        $this->receivedGroups = $groups;
        $this->receivedSelection = $selection;

        return new DeepReviewResult(
            findings: $this->findings,
            inputTokens: 2000,
            outputTokens: 400,
        );
    }
}
