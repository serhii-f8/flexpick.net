<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Services\AuditReport\Findings\FindingGroup;

/**
 * The "Repository facts" panel, computed once for both report views.
 *
 * Exists because the views read top-level keys (`has_ci`, `test_ratio_pct`,
 * `secret_findings`) that the collectors store elsewhere or no longer write,
 * so a customer saw "CI: no / 0% tests / 0 secrets" next to a testing score
 * of 100 and 131 secret findings. Legacy keys are still read so reports
 * stored before the fix render correctly too.
 */
final class RepositoryFacts
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  iterable<AuditFindingGroup|FindingGroup>  $groups
     * @return array{files_total: int, loc_total: int, duplication_pct: ?float, test_ratio_pct: float, has_ci: bool, ci_systems: list<string>, secrets_likely: int, secrets_fixtures: int, vulnerable_dependencies: ?int, excluded_files: int}
     */
    public static function from(array $metrics, iterable $groups): array
    {
        $tooling = is_array($metrics['tooling'] ?? null) ? $metrics['tooling'] : [];
        $likely = 0;
        $fixtures = 0;
        $sawSecretGroups = false;

        foreach ($groups as $group) {
            [$family, $severity, $count] = $group instanceof FindingGroup
                ? [$group->ruleFamily, $group->severity->value, $group->count]
                : [(string) $group->rule_family, (string) $group->severity, (int) $group->count];

            if (! str_starts_with($family, 'secrets.')) {
                continue;
            }

            $sawSecretGroups = true;
            in_array($severity, ['critical', 'high'], true) ? $likely += $count : $fixtures += $count;
        }

        if (! $sawSecretGroups) {
            $likely = (int) array_sum(array_column((array) ($metrics['secret_findings'] ?? []), 'count'));
        }

        $audit = $metrics['dependency_audit'] ?? null;

        return [
            'files_total' => (int) ($metrics['files_total'] ?? 0),
            'loc_total' => (int) ($metrics['loc_total'] ?? 0),
            'duplication_pct' => in_array('duplication', (array) ($metrics['not_measured'] ?? []), true)
                ? null
                : (float) ($metrics['duplication_pct'] ?? 0),
            'test_ratio_pct' => (float) ($tooling['test_ratio_pct'] ?? $metrics['test_ratio_pct'] ?? 0),
            'has_ci' => (bool) ($tooling['has_ci'] ?? $metrics['has_ci'] ?? false),
            'ci_systems' => array_values((array) ($tooling['ci_systems'] ?? [])),
            'secrets_likely' => $likely,
            'secrets_fixtures' => $fixtures,
            'vulnerable_dependencies' => is_array($audit) && ! isset($audit['error']) ? (int) ($audit['vulnerable_count'] ?? 0) : null,
            'excluded_files' => (int) array_sum((array) ($metrics['excluded_summary'] ?? [])),
        ];
    }
}
