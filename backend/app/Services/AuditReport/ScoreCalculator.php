<?php

namespace App\Services\AuditReport;

class ScoreCalculator
{
    /**
     * Bump when any formula in calculate() changes. Reports record the version
     * that produced them; deltas and benchmarks only compare within a version.
     */
    public const VERSION = 1;

    /**
     * Deterministic 0-100 health scores computed from measured metrics.
     * The LLM narrates; these numbers are authoritative so repeat runs
     * of the same repo score identically (trends/deltas depend on this).
     */
    public function calculate(array $metrics): array
    {
        $duplication = $this->clamp(100 - 2.5 * (float) ($metrics['duplication_pct'] ?? 0));

        $testing = $this->clamp(
            min(90, 4.5 * (float) ($metrics['test_ratio_pct'] ?? 0))
            + (($metrics['has_ci'] ?? false) ? 10 : 0)
        );

        $files = max(1, (int) ($metrics['files_total'] ?? 1));
        $avgLoc = ((int) ($metrics['loc_total'] ?? 0)) / $files;
        $huge = count(array_filter($metrics['largest_files'] ?? [], fn (array $f) => ($f['loc'] ?? 0) >= 1000));
        $big = count(array_filter($metrics['largest_files'] ?? [], fn (array $f) => ($f['loc'] ?? 0) >= 500 && ($f['loc'] ?? 0) < 1000));
        $structure = $this->clamp(100 - max(0, $avgLoc - 120) * 0.25 - 8 * $huge - 3 * $big);

        $dependencies = 100;
        foreach (($metrics['manifests'] ?? []) as $manifest) {
            if (! ($manifest['lockfile'] ?? false)) {
                $dependencies -= 20;
            }
        }
        $audit = $metrics['dependency_audit'] ?? null;
        if (is_array($audit) && isset($audit['error'])) {
            $dependencies = min($dependencies, 70);
        } elseif (is_array($audit)) {
            $dependencies -= 8 * (int) ($audit['vulnerable_count'] ?? 0);
        }
        $dependencies = $this->clamp($dependencies);

        $secretCount = array_sum(array_column($metrics['secret_findings'] ?? [], 'count'));
        $securityHygiene = $this->clamp(100 - 15 * $secretCount);

        $overall = (int) round(
            0.25 * $structure
            + 0.20 * $duplication
            + 0.25 * $testing
            + 0.15 * $dependencies
            + 0.15 * $securityHygiene
        );

        return [
            'structure' => $structure,
            'duplication' => $duplication,
            'testing' => $testing,
            'dependencies' => $dependencies,
            'security_hygiene' => $securityHygiene,
            'overall' => $overall,
        ];
    }

    private function clamp(float $value): int
    {
        return (int) round(max(0, min(100, $value)));
    }
}
