<?php

namespace App\Health\Checks;

use App\Constants\AuditRequestStatus;
use App\Health\FailureRate;
use App\Models\AuditRequest;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class AuditPipelineFailureRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.pipeline_failure');
        $since = now()->subHours((int) $config['window_hours']);

        $total = AuditRequest::query()->where('created_at', '>=', $since)->count();

        $failed = AuditRequest::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', [
                AuditRequestStatus::FAILED->value,
                AuditRequestStatus::NEEDS_FOLLOWUP->value,
            ])
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
