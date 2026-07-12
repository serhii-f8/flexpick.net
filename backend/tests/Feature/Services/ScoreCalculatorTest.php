<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\ScoreCalculator;
use Tests\Feature\FeatureTest;

class ScoreCalculatorTest extends FeatureTest
{
    private function metrics(): array
    {
        return [
            'files_total' => 100,
            'loc_total' => 20000, // avg 200 → structure base 80
            'largest_files' => [
                ['path' => 'a.php', 'loc' => 1200], // −8
                ['path' => 'b.php', 'loc' => 800],  // −3
                ['path' => 'c.php', 'loc' => 600],  // −3
            ],
            'duplication_pct' => 20.0, // 100 − 50 = 50
            'test_ratio_pct' => 10.0,  // min(90, 45) = 45
            'has_ci' => true,          // +10 → 55
            'manifests' => ['composer.json' => ['dependencies' => 10, 'dev_dependencies' => 5, 'lockfile' => true]],
            'dependency_audit' => ['packages_scanned' => 40, 'vulnerable_count' => 2, 'vulnerabilities' => []], // 100 − 16 = 84
            'secret_findings' => ['github_token' => ['count' => 1, 'files' => ['x']]], // 100 − 15 = 85
        ];
    }

    public function test_calculates_exact_scores_from_metrics(): void
    {
        $scores = app(ScoreCalculator::class)->calculate($this->metrics());

        $this->assertSame([
            'structure' => 66,
            'duplication' => 50,
            'testing' => 55,
            'dependencies' => 84,
            'security_hygiene' => 85,
            'overall' => 66, // 16.5 + 10 + 13.75 + 12.6 + 12.75 = 65.6 → 66
        ], $scores);
    }

    public function test_is_deterministic_and_clamped(): void
    {
        $calculator = app(ScoreCalculator::class);
        $extreme = ['duplication_pct' => 90, 'test_ratio_pct' => 0, 'has_ci' => false,
            'files_total' => 1, 'loc_total' => 5000, 'largest_files' => [],
            'manifests' => ['package.json' => ['dependencies' => 1, 'dev_dependencies' => 0, 'lockfile' => false]],
            'dependency_audit' => ['vulnerable_count' => 30, 'error' => 'osv_unreachable'],
            'secret_findings' => ['aws_access_key' => ['count' => 12, 'files' => []]],
        ];

        $first = $calculator->calculate($extreme);

        $this->assertSame($first, $calculator->calculate($extreme));
        foreach ($first as $score) {
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
        $this->assertSame(0, $first['duplication']);
        $this->assertLessThanOrEqual(70, $first['dependencies']); // errored scan caps at 70
    }

    public function test_handles_missing_keys_gracefully(): void
    {
        $scores = app(ScoreCalculator::class)->calculate([]);

        $this->assertSame(100, $scores['duplication']);
        $this->assertSame(0, $scores['testing']);
        $this->assertSame(100, $scores['security_hygiene']);
    }
}
