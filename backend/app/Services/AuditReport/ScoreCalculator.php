<?php

namespace App\Services\AuditReport;

use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;

/**
 * Deterministic 0-100 health scores computed from measured metrics and
 * scanner findings. The LLM narrates; these numbers are authoritative, so
 * repeat runs of the same repository score identically (deltas depend on it).
 *
 * Findings feed the formulas; the formulas never feed the findings (F5.12.2).
 */
class ScoreCalculator
{
    /**
     * Bump when any formula below changes. Reports record the version that
     * produced them; deltas and benchmarks only compare within a version.
     *
     * v2 (Phase 11): duplication now from jscpd, security_hygiene from
     * Gitleaks + Semgrep, structure from scc complexity.
     */
    public const VERSION = 2;

    /** Which scanner each dimension depends on. A dimension whose scanner did not run is not measured. */
    private const DIMENSION_SCANNERS = [
        'structure' => ['scc'],
        'duplication' => ['jscpd'],
        'testing' => [],            // collector-driven; always measurable
        'dependencies' => ['osv'],
        'security_hygiene' => ['gitleaks', 'semgrep'],
    ];

    private const WEIGHTS = [
        'structure' => 0.25,
        'duplication' => 0.20,
        'testing' => 0.25,
        'dependencies' => 0.15,
        'security_hygiene' => 0.15,
    ];

    /**
     * @param  list<FindingGroup>  $groups
     */
    public function calculate(array $metrics, array $groups, ScannerSuiteResult $runs): ScoreSet
    {
        $scores = [];
        $notMeasured = [];

        foreach (self::DIMENSION_SCANNERS as $dimension => $required) {
            if (! $this->allRan($required, $runs)) {
                $notMeasured[] = $dimension;

                continue;
            }

            $scores[$dimension] = match ($dimension) {
                'structure' => $this->structure($metrics, $this->forDimension($groups, 'structure')),
                'duplication' => $this->duplication($metrics),
                'testing' => $this->testing($metrics),
                'dependencies' => $this->dependencies($metrics, $this->forDimension($groups, 'dependencies')),
                'security_hygiene' => $this->securityHygiene($this->forDimension($groups, 'security_hygiene')),
            };
        }

        sort($notMeasured);

        $scores['overall'] = $this->overall($scores);

        return new ScoreSet($scores, $notMeasured, self::VERSION);
    }

    /** @param list<string> $required */
    private function allRan(array $required, ScannerSuiteResult $runs): bool
    {
        foreach ($required as $scanner) {
            if (! $runs->ranSuccessfully($scanner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renormalize over measured dimensions so a missing dimension does not
     * drag the overall toward zero — it is unknown, not bad.
     *
     * @param  array<string, int>  $scores
     */
    private function overall(array $scores): int
    {
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach (self::WEIGHTS as $dimension => $weight) {
            if (! isset($scores[$dimension])) {
                continue;
            }

            $weighted += $weight * $scores[$dimension];
            $totalWeight += $weight;
        }

        return $totalWeight > 0.0 ? (int) round($weighted / $totalWeight) : 0;
    }

    /**
     * Findings route to a dimension by the dimension THEY declare — never by
     * which tool produced them. A Semgrep rule tagged `dimension: structure`
     * scores structure, which is the whole point of the rule metadata
     * (spec §5.3, §7.1) and keeps one mapping instead of two.
     *
     * @param  list<FindingGroup>  $groups
     * @return list<FindingGroup>
     */
    private function forDimension(array $groups, string $dimension): array
    {
        return array_values(array_filter(
            $groups,
            fn (FindingGroup $group): bool => $group->dimension === $dimension,
        ));
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `structure` */
    private function structure(array $metrics, array $groups): int
    {
        $files = max(1, (int) ($metrics['files_total'] ?? 1));
        $avgLoc = ((int) ($metrics['loc_total'] ?? 0)) / $files;
        $avgComplexity = ((int) ($metrics['complexity_total'] ?? 0)) / $files;

        $huge = count(array_filter($metrics['largest_files'] ?? [], fn (array $f): bool => ($f['loc'] ?? 0) >= 1000));
        $big = count(array_filter(
            $metrics['largest_files'] ?? [],
            fn (array $f): bool => ($f['loc'] ?? 0) >= 500 && ($f['loc'] ?? 0) < 1000,
        ));

        // Maintainability and correctness rules (dimension: structure) count here.
        $ruleFindings = array_sum(array_map(fn (FindingGroup $g): int => $g->count, $groups));

        return $this->clamp(
            100
            - max(0, $avgLoc - 120) * 0.25
            - max(0, $avgComplexity - 8) * 1.5
            - 8 * $huge
            - 3 * $big
            - min(20, $ruleFindings)
        );
    }

    private function duplication(array $metrics): int
    {
        return $this->clamp(100 - 2.5 * (float) ($metrics['duplication_pct'] ?? 0));
    }

    private function testing(array $metrics): int
    {
        return $this->clamp(
            min(90, 4.5 * (float) ($metrics['tooling']['test_ratio_pct'] ?? 0))
            + (($metrics['tooling']['has_ci'] ?? false) ? 10 : 0)
        );
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `dependencies` */
    private function dependencies(array $metrics, array $groups): int
    {
        $score = 100;

        foreach (($metrics['manifests'] ?? []) as $manifest) {
            if (! ($manifest['lockfile'] ?? false)) {
                $score -= 20;
            }
        }

        $score -= 8 * array_sum(array_map(fn (FindingGroup $g): int => $g->count, $groups));

        return $this->clamp($score);
    }

    /** @param list<FindingGroup> $groups groups declaring dimension `security_hygiene` */
    private function securityHygiene(array $groups): int
    {
        $score = 100;

        foreach ($groups as $group) {
            // A committed credential is categorically worse than a SAST hit,
            // so secrets keep their own weight within the same dimension.
            $score -= str_starts_with($group->ruleFamily, 'secrets.')
                ? 15 * $group->count
                : min(20, $group->count * 2);
        }

        return $this->clamp($score);
    }

    private function clamp(float $value): int
    {
        return (int) round(max(0, min(100, $value)));
    }
}
