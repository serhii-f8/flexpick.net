<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Tiers\TierProfile;

interface AiAnalyzer
{
    /**
     * @param  list<FindingGroup>  $groups              ranked, already capped to the tier's narrated_groups
     * @param  list<array{path: string, content: string}>  $excerpts
     */
    public function analyze(
        array $metrics,
        array $groups,
        array $excerpts,
        TierProfile $tier,
        ?string $adminContext = null,
    ): AnalysisResult;
}
