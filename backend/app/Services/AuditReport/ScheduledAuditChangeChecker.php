<?php

namespace App\Services\AuditReport;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Models\AuditSchedule;

class ScheduledAuditChangeChecker
{
    public function __construct(private RepositoryCloner $cloner) {}

    public function check(AuditSchedule $schedule): ChangeCheckResult
    {
        try {
            $sha = $this->cloner->remoteHeadSha($schedule->repo_url, $schedule->branch, tenant: $schedule->tenant);
        } catch (GitAccessTemporarilyUnavailableException) {
            // Neither "changed" nor a failure to record: the credential is only
            // momentarily unobtainable. Skip this cycle; the next one checks again.
            return new ChangeCheckResult(shouldRun: false, sha: null, unavailable: true);
        }

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
