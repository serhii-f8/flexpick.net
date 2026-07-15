<?php

namespace Tests\Support;

use App\Services\AuditReport\AiAnalyzer;

class FakeAiAnalyzer implements AiAnalyzer
{
    public ?array $receivedMetrics = null;

    public ?string $receivedAdminContext = null;

    public function __construct(
        public ?\Throwable $throws = null,
    ) {}

    public function analyze(array $metrics, array $excerpts, ?string $adminContext = null): array
    {
        if ($this->throws) {
            throw $this->throws;
        }

        $this->receivedMetrics = $metrics;
        $this->receivedAdminContext = $adminContext;

        return [
            'summary' => 'Fake analysis summary.',
            'scores' => ['structure' => 62, 'duplication' => 40, 'testing' => 15, 'dependencies' => 70, 'security_hygiene' => 55, 'overall' => 48],
            'risks' => [['title' => 'Low test coverage', 'impact' => 'high', 'evidence' => 'test ratio', 'recommendation' => 'Add smoke tests']],
            'fix_first_plan' => [['step' => 'Set up CI', 'why' => 'Catch regressions early', 'effort' => 'S']],
        ];
    }
}
