<?php

namespace Tests\Support;

use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AnalysisResult;
use App\Services\AuditReport\Tiers\TierProfile;

class FakeAiAnalyzer implements AiAnalyzer
{
    public ?array $receivedMetrics = null;

    /** @var list<\App\Services\AuditReport\Findings\FindingGroup>|null */
    public ?array $receivedGroups = null;

    public ?string $receivedAdminContext = null;

    public function __construct(
        public ?\Throwable $throws = null,
    ) {}

    public function analyze(
        array $metrics,
        array $groups,
        array $excerpts,
        TierProfile $tier,
        ?string $adminContext = null,
    ): AnalysisResult {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;
        $this->receivedGroups = $groups;
        $this->receivedAdminContext = $adminContext;

        return new AnalysisResult(
            payload: [
                'summary' => 'Fake analysis summary.',
                // Overwritten by the pipeline with the real ScoreSet before storage.
                'scores' => ['overall' => 48],
                'risks' => [['title' => 'Low test coverage', 'impact' => 'high', 'evidence' => 'test ratio', 'recommendation' => 'Add smoke tests']],
                'fix_first_plan' => [['step' => 'Set up CI', 'why' => 'Catch regressions early', 'effort' => 'S']],
                'groups' => [],
            ],
            inputTokens: 100,
            outputTokens: 50,
        );
    }
}
