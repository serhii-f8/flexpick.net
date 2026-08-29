<?php

namespace App\Services\AuditReport;

use App\Models\AuditSchedule;

class ScheduledAuditChangeChecker
{
    public function __construct(private RepositoryCloner $cloner) {}

    public function check(AuditSchedule $schedule): ChangeCheckResult
    {
        $sha = $this->cloner->remoteHeadSha($schedule->repo_url, $schedule->branch);

        // Fail open: an unreadable SHA (network error, transient outage) is
        // indistinguishable here from "definitely changed" -- both must let
        // the run proceed (spec: change check fails open).
        if ($sha === null) {
            return new ChangeCheckResult(shouldRun: true, sha: null);
        }

        if ($schedule->last_commit_sha !== null && $schedule->last_commit_sha === $sha) {
            return new ChangeCheckResult(shouldRun: false, sha: $sha);
        }

        return new ChangeCheckResult(shouldRun: true, sha: $sha);
    }
}
