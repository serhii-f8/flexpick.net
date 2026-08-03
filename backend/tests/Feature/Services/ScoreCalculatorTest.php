<?php

namespace Tests\Feature\Services;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\ScoreCalculator;
use App\Services\AuditReport\Scanners\ScannerOutcome;
use App\Services\AuditReport\Scanners\ScannerRun;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use Tests\Feature\FeatureTest;

class ScoreCalculatorTest extends FeatureTest
{
    private function metrics(): array
    {
        return [
            'files_total' => 100,
            'loc_total' => 20000,
            'complexity_total' => 800,
            'largest_files' => [
                ['path' => 'a.php', 'loc' => 1200],
                ['path' => 'b.php', 'loc' => 800],
            ],
            'tooling' => ['test_ratio_pct' => 10.0, 'has_ci' => true],
            'manifests' => ['composer.json' => ['dependencies' => 10, 'dev_dependencies' => 5, 'lockfile' => true]],
            'duplication_pct' => 20.0,
        ];
    }

    /** @param list<string> $ok */
    private function runs(array $ok, array $failed = []): ScannerSuiteResult
    {
        $runs = [];

        foreach ($ok as $name) {
            $runs[] = new ScannerRun($name, '1.0', 10, 0, ScannerOutcome::OK);
        }

        foreach ($failed as $name) {
            $runs[] = new ScannerRun($name, '1.0', 10, 0, ScannerOutcome::TIMEOUT, 'timeout');
        }

        return new ScannerSuiteResult([], $runs);
    }

    private function group(
        string $family,
        Severity $severity,
        int $count,
        string $dimension = 'security_hygiene',
    ): FindingGroup {
        return new FindingGroup(
            ruleFamily: $family,
            directory: 'app',
            severity: $severity,
            count: $count,
            score: $severity->weight() * $count,
            examples: [],
            tools: ['semgrep'],
            dimension: $dimension,
        );
    }

    public function test_a_group_scores_the_dimension_it_declares_not_the_tool_that_found_it(): void
    {
        // A Semgrep rule tagged `dimension: structure` must move structure,
        // not security_hygiene (spec §5.3, §7.1).
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $clean = $calculator->calculate($this->metrics(), [], $runs);
        $structural = $calculator->calculate($this->metrics(), [
            $this->group('common.configuration', Severity::MEDIUM, 10, 'structure'),
        ], $runs);

        $this->assertLessThan($clean->scores['structure'], $structural->scores['structure']);
        $this->assertSame($clean->scores['security_hygiene'], $structural->scores['security_hygiene']);
    }

    private function allScanners(): array
    {
        return ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'];
    }

    public function test_reports_the_current_scoring_version(): void
    {
        $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $this->runs($this->allScanners()));

        $this->assertSame(2, $set->scoringVersion);
        $this->assertSame(2, ScoreCalculator::VERSION);
    }

    public function test_scores_every_dimension_when_all_scanners_ran(): void
    {
        $set = app(ScoreCalculator::class)->calculate($this->metrics(), [], $this->runs($this->allScanners()));

        $this->assertSame([], $set->notMeasured);

        foreach (['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'] as $key) {
            $this->assertArrayHasKey($key, $set->scores);
            $this->assertIsInt($set->scores[$key]);
        }
    }

    public function test_a_failed_semgrep_does_not_score_security_as_perfect(): void
    {
        // THE trap: zero findings from a scanner that never ran is not a clean repo.
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'jscpd'], failed: ['semgrep']),
        );

        $this->assertContains('security_hygiene', $set->notMeasured);
        $this->assertArrayNotHasKey('security_hygiene', $set->scores);
    }

    public function test_a_failed_jscpd_marks_duplication_not_measured(): void
    {
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'semgrep'], failed: ['jscpd']),
        );

        $this->assertContains('duplication', $set->notMeasured);
    }

    public function test_overall_renormalizes_over_measured_dimensions_only(): void
    {
        $calculator = app(ScoreCalculator::class);

        $full = $calculator->calculate($this->metrics(), [], $this->runs($this->allScanners()));
        $partial = $calculator->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv', 'jscpd'], failed: ['semgrep']),
        );

        // Dropping a dimension must not drag overall toward zero — the
        // remaining weights are renormalized to sum to 1.
        $this->assertGreaterThan(0, $partial->scores['overall']);
        $this->assertLessThanOrEqual(100, $partial->scores['overall']);
        $this->assertNotSame($full->scores['overall'], $partial->scores['overall']);
    }

    public function test_the_diagnostic_tier_structurally_lacks_duplication_and_security(): void
    {
        // diagnostic runs neither jscpd nor semgrep by design (spec §4.1),
        // so both dimensions are honestly not-measured rather than invented.
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv']),
        );

        $this->assertContains('duplication', $set->notMeasured);
        $this->assertContains('security_hygiene', $set->notMeasured);
    }

    public function test_gitleaks_findings_drive_security_hygiene_down(): void
    {
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $clean = $calculator->calculate($this->metrics(), [], $runs);
        $leaky = $calculator->calculate($this->metrics(), [
            $this->group('secrets.credential', Severity::CRITICAL, 3),
        ], $runs);

        $this->assertLessThan($clean->scores['security_hygiene'], $leaky->scores['security_hygiene']);
    }

    public function test_duplication_score_comes_from_the_measured_percentage(): void
    {
        $metrics = $this->metrics();
        $metrics['duplication_pct'] = 0.0;

        $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

        $this->assertSame(100, $set->scores['duplication']);
    }

    public function test_scores_are_clamped_to_zero_through_one_hundred(): void
    {
        $metrics = $this->metrics();
        $metrics['duplication_pct'] = 500.0;

        $set = app(ScoreCalculator::class)->calculate($metrics, [], $this->runs($this->allScanners()));

        $this->assertSame(0, $set->scores['duplication']);
    }

    public function test_is_deterministic(): void
    {
        $calculator = app(ScoreCalculator::class);
        $runs = $this->runs($this->allScanners());

        $this->assertEquals(
            $calculator->calculate($this->metrics(), [], $runs),
            $calculator->calculate($this->metrics(), [], $runs),
        );
    }

    public function test_payload_scores_omit_not_measured_dimensions(): void
    {
        $set = app(ScoreCalculator::class)->calculate(
            $this->metrics(),
            [],
            $this->runs(['scc', 'gitleaks', 'osv']),
        );

        $this->assertArrayNotHasKey('duplication', $set->toPayloadScores());
        $this->assertArrayHasKey('overall', $set->toPayloadScores());
    }
}
