<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use Illuminate\Support\Carbon;

/**
 * Compares persisted finding groups between the current report and the most
 * recent previous report for the same email + repo_url + branch, so a report
 * can say which issues were fixed, which are new, and which persisted with a
 * changed count -- at the group level (rule_family + directory + dimension),
 * not per raw finding. See docs/superpowers/specs/2026-09-02-audit-full-issues-delta-design.md.
 */
class AuditGroupDeltaService
{
    private const PRE_MIGRATION_GROUP_CAP = 20;

    /**
     * @return array{
     *     previous_at: Carbon,
     *     groups: array<string, array{
     *         rule_family: string, directory: string, dimension: string,
     *         status: 'new'|'fixed'|'persisting',
     *         count: int|null, previous_count: int|null, count_delta: int|null,
     *     }>,
     *     summary: array{fixed_groups: int, new_groups: int, resolved_findings: int, new_findings: int},
     * }|null
     */
    public function deltasFor(AuditReport $report): ?array
    {
        $auditRequest = $report->auditRequest;
        $repoUrl = rtrim((string) $auditRequest->repo_url, '/');

        if ($repoUrl === '') {
            return null;
        }

        $previousReport = AuditReport::query()
            ->whereHas('auditRequest', fn ($query) => $query
                ->where('email', $auditRequest->email)
                ->whereIn('repo_url', [$repoUrl, $repoUrl.'/'])
                ->where('branch', $auditRequest->branch))
            ->where('id', '<', $report->id)
            ->where('scoring_version', $report->scoring_version)
            ->latest('id')
            ->first();

        if ($previousReport === null) {
            return null;
        }

        // The pre-migration group cap (config('audit.findings.max_groups') was 20
        // before 2026-09-02's uncap, config/audit.php). A previous run with exactly
        // 20 persisted groups may have been silently truncated -- comparing against
        // it would report every group beyond the old cap as spuriously "new" rather
        // than skip the comparison. A repo that genuinely has exactly 20 groups
        // post-migration loses one comparison (degrades to "no delta shown"), which
        // is the safe direction to fail in.
        if (AuditFindingGroup::query()->where('audit_request_id', $previousReport->audit_request_id)->count() === self::PRE_MIGRATION_GROUP_CAP) {
            return null;
        }

        $current = $this->countsByKey((int) $report->audit_request_id);
        $previous = $this->countsByKey((int) $previousReport->audit_request_id);

        $groups = [];
        $summary = ['fixed_groups' => 0, 'new_groups' => 0, 'resolved_findings' => 0, 'new_findings' => 0];

        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $key) {
            $currentGroup = $current[$key] ?? null;
            $previousGroup = $previous[$key] ?? null;

            if ($currentGroup !== null && $previousGroup === null) {
                $groups[$key] = [...$currentGroup, 'status' => 'new', 'previous_count' => null, 'count_delta' => null];
                $summary['new_groups']++;
                $summary['new_findings'] += $currentGroup['count'];

                continue;
            }

            if ($currentGroup === null && $previousGroup !== null) {
                $groups[$key] = [
                    ...$previousGroup,
                    'count' => null,
                    'status' => 'fixed',
                    'previous_count' => $previousGroup['count'],
                    'count_delta' => null,
                ];
                $summary['fixed_groups']++;
                $summary['resolved_findings'] += $previousGroup['count'];

                continue;
            }

            $delta = $currentGroup['count'] - $previousGroup['count'];
            $groups[$key] = [
                ...$currentGroup,
                'status' => 'persisting',
                'previous_count' => $previousGroup['count'],
                'count_delta' => $delta,
            ];

            if ($delta > 0) {
                $summary['new_findings'] += $delta;
            } elseif ($delta < 0) {
                $summary['resolved_findings'] += abs($delta);
            }
        }

        return ['previous_at' => $previousReport->created_at, 'groups' => $groups, 'summary' => $summary];
    }

    /** @return array<string, array{rule_family: string, directory: string, dimension: string, count: int}> */
    private function countsByKey(int $auditRequestId): array
    {
        return AuditFindingGroup::query()
            ->where('audit_request_id', $auditRequestId)
            ->get(['rule_family', 'directory', 'dimension', 'count'])
            ->mapWithKeys(fn (AuditFindingGroup $group): array => [
                "{$group->rule_family}|{$group->directory}|{$group->dimension}" => [
                    'rule_family' => $group->rule_family,
                    'directory' => $group->directory,
                    'dimension' => $group->dimension,
                    'count' => $group->count,
                ],
            ])
            ->all();
    }
}
