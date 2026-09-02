<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;

/**
 * Compares persisted finding groups between the current report and the most
 * recent previous report for the same email + repo_url + branch, so a report
 * can say which issues were fixed, which are new, and which persisted with a
 * changed count -- at the group level (rule_family + directory + dimension),
 * not per raw finding. See docs/superpowers/specs/2026-09-02-audit-full-issues-delta-design.md.
 */
class AuditGroupDeltaService
{
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
