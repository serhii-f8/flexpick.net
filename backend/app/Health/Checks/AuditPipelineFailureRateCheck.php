<?php

namespace App\Health\Checks;

use App\Constants\AuditRequestStatus;
use App\Health\FailureRate;
use App\Models\AuditRequest;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * System failure rate of the audit pipeline over *attempted runs*.
 *
 * Denominator: requests whose `analysis_started_at` falls inside the window,
 * i.e. runs the pipeline actually attempted. Statuses such as `new` and
 * `pending_verification` never ran, so counting them would not produce a rate
 * over runs at all.
 *
 * Numerator: `failed` only. `needs_followup` is deliberately excluded because
 * it marks *user-caused* conditions — a private repository, a repository over
 * the size limit, a missing URL — where the pipeline ran and concluded
 * correctly. Counting those as system failures means a handful of private or
 * typo'd repositories at pre-launch volume trips a High-band failure, which
 * pins /health at 503 for up to a day. That does not just page falsely: while
 * the endpoint is already 503, a genuinely dead scheduler produces no change
 * in signal, so it masks the staleness dead-man's switch. Such a run still
 * counts in the denominator — the pipeline did run — it just is not a failure.
 */
class AuditPipelineFailureRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.pipeline_failure');
        $since = now()->subHours((int) $config['window_hours']);

        $total = AuditRequest::query()
            ->whereNotNull('analysis_started_at')
            ->where('analysis_started_at', '>=', $since)
            ->count();

        $failed = AuditRequest::query()
            ->whereNotNull('analysis_started_at')
            ->where('analysis_started_at', '>=', $since)
            ->where('status', AuditRequestStatus::FAILED->value)
            ->count();

        $rate = new FailureRate($total, $failed, (int) $config['min_samples']);

        $result = Result::make()
            ->meta([
                'total' => $rate->total,
                'failed' => $rate->failed,
                'percent' => $rate->percent(),
            ])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if ($rate->belowFloor()) {
            return $result->ok();
        }

        if ($rate->percent() > (int) $config['fail_percent']) {
            return $result->failed(
                "Audit pipeline failure rate is {$rate->percent()}% over the last {$config['window_hours']}h ({$rate->failed}/{$rate->total})."
            );
        }

        return $result->ok();
    }
}
