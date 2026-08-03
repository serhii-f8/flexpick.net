<?php

namespace App\Services\AuditReport;

use App\Models\AuditReport;

class AuditDeltaService
{
    private const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'];

    public function deltasFor(AuditReport $report): ?array
    {
        $repoUrl = rtrim((string) $report->auditRequest->repo_url, '/');

        if ($repoUrl === '') {
            return null;
        }

        $previous = AuditReport::query()
            ->whereHas('auditRequest', fn ($query) => $query
                ->where('email', $report->auditRequest->email)
                ->whereIn('repo_url', [$repoUrl, $repoUrl.'/']))
            ->where('id', '<', $report->id)
            ->where('scoring_version', $report->scoring_version)
            ->latest('id')
            ->first();

        if ($previous === null) {
            return null;
        }

        $deltas = [];
        foreach (self::DIMENSIONS as $dimension) {
            $deltas[$dimension] = (int) data_get($report->payload, "scores.$dimension", 0)
                - (int) data_get($previous->payload, "scores.$dimension", 0);
        }

        return ['previous_at' => $previous->created_at, 'deltas' => $deltas];
    }
}
