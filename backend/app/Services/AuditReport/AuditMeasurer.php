<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Paths\PathClassifier;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Scanners\SccScanner;

/**
 * Scanners → findings → collectors → scores: everything deterministic about a
 * run. The pipeline wraps it with sizing, AI and delivery; app:audit-dry-run
 * and the regression fixture call it bare, so they exercise the same code a
 * customer's run does.
 */
class AuditMeasurer
{
    public function __construct(
        private ScannerRunner $scannerRunner,
        private SccScanner $sccScanner,
        private FindingDeduplicator $deduplicator,
        private FindingGrouper $grouper,
        private MetricsCollector $metricsCollector,
        private ScoreCalculator $scoreCalculator,
    ) {}

    /**
     * Scanners first — scc's inventory sizes the budgets for everything after
     * it, including excerpt selection (spec §3.2). scc runs alone and
     * `$afterInventory` runs before any other scanner is paid for, so a caller
     * can size the request and close an oversized or uncovered one there
     * (spec §2).
     *
     * @param  list<string>  $scanners  the tier's scanners; scc always runs first
     * @param  (callable(SccInventory, bool): void)|null  $afterInventory  may throw to stop the run
     */
    public function measure(RepoContext $context, array $scanners, ?callable $afterInventory = null): Measurement
    {
        $sccSuite = $this->scannerRunner->run(['scc'], $context);

        // scc failing must not leave later stages without a basis (spec §10).
        $fellBack = $context->inventory === null;
        if ($fellBack) {
            $context->withClassifier(PathClassifier::forRepository($context->path));
            $context->withInventory($this->sccScanner->fallbackInventory($context->path, $context->classifier));
        }

        if ($afterInventory !== null) {
            $afterInventory($context->inventory, $fellBack);
        }

        $suite = $sccSuite->merge($this->scannerRunner->run(
            array_values(array_filter($scanners, fn (string $name): bool => $name !== 'scc')),
            $context,
        ));

        $groups = $this->grouper->group($this->deduplicator->dedupe($suite->findings));

        // Q17: excerpt collection (every tier) and risk-file selection
        // both read this. Derived from findings, not from the scanner.
        $context->withSecretPaths($this->secretPaths($suite));

        $collected = $this->metricsCollector->collect($context);
        $metrics = $collected['metrics'];
        // Recorded by JscpdScanner on the per-run context.
        $metrics['duplication_pct'] = (float) $context->measurement('duplication_pct', 0.0);

        if ($suite->ranSuccessfully('osv')) {
            $metrics['dependency_audit'] = [
                'packages_scanned' => (int) $context->measurement('packages_scanned', 0),
                'vulnerable_count' => (int) $context->measurement('vulnerable_count', 0),
            ];
        }

        $scores = $this->scoreCalculator->calculate($metrics, $groups, $suite);
        $metrics['computed_scores'] = $scores->toPayloadScores();
        $metrics['not_measured'] = $scores->notMeasured;
        $metrics['not_measured_reasons'] = $scores->notMeasuredReasons;

        return new Measurement($suite, $groups, $metrics, $collected['excerpts'], $scores);
    }

    // No duplicationPercentage() helper: JscpdScanner records the figure on
    // RepoContext during its own scan, and ScoreCalculator marks the
    // duplication dimension not-measured when jscpd did not run — so a
    // missing measurement can never be mistaken for a duplication-free repo.

    /** @return list<string> */
    private function secretPaths(ScannerSuiteResult $suite): array
    {
        $paths = [];

        foreach ($suite->findings as $finding) {
            if ($finding->tool === 'gitleaks') {
                $paths[] = $finding->path;
            }
        }

        return array_values(array_unique($paths));
    }
}
