<?php

namespace App\Health\Checks;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Spec §18.5 SC1 and §18.3 O1.
 *
 * QueueCheck proves a worker round-trips its own heartbeat job; it stays green
 * while real audit jobs pile up behind a poison message. This watches the age
 * of the oldest genuinely pending request instead.
 */
class OldestPendingAuditCheck extends Check
{
    public function run(): Result
    {
        $queuedAge = $this->ageInMinutes(
            AuditRequest::query()
                ->where('status', AuditRequestStatus::QUEUED->value)
                ->min('updated_at')
        );

        $analyzingAge = $this->ageInMinutes(
            AuditRequest::query()
                ->where('status', AuditRequestStatus::ANALYZING->value)
                ->min('analysis_started_at')
        );

        $queuedLimit = (int) config('health.flexpick.oldest_queued_minutes');
        $analyzingLimit = (int) config('health.flexpick.oldest_analyzing_minutes');

        $result = Result::make()
            ->meta([
                'queued_age_minutes' => $queuedAge,
                'analyzing_age_minutes' => $analyzingAge,
            ])
            ->shortSummary("queued {$queuedAge}m / analyzing {$analyzingAge}m");

        if ($queuedAge > $queuedLimit) {
            return $result->failed(
                "Oldest queued audit has been waiting {$queuedAge} minutes (limit {$queuedLimit})."
            );
        }

        if ($analyzingAge > $analyzingLimit) {
            return $result->failed(
                "An audit has been analyzing for {$analyzingAge} minutes (limit {$analyzingLimit}); a worker likely died mid-run."
            );
        }

        return $result->ok();
    }

    private function ageInMinutes(mixed $timestamp): int
    {
        if ($timestamp === null) {
            return 0;
        }

        // Carbon 3 returns a signed diff by default; force absolute.
        return (int) Carbon::parse($timestamp)->diffInMinutes(now(), true);
    }
}
