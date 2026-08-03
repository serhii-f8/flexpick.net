<?php

namespace App\Health\Checks;

use App\Health\FailureRate;
use App\Models\AuditRequest;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Rate of runs in which a scanner did not complete.
 *
 * A failed scanner never fails the run (F5.12.2) and the customer sees the
 * affected dimension marked not-measured (spec §7.2) — but nothing tells the
 * *operator*. Without this check, selling systematically thin $49 reports
 * produces no signal at all.
 *
 * Denominator: runs that actually recorded scanner provenance within the
 * window. Numerator: runs where at least one scanner's outcome was not `ok`.
 */
class ScannerDegradationCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.scanner_degradation');
        $since = now()->subHours((int) $config['window_hours']);

        $runs = AuditRequest::query()
            ->whereNotNull('scanner_runs')
            ->where('analysis_started_at', '>=', $since)
            ->pluck('scanner_runs');

        $total = $runs->count();
        $degraded = $runs->filter(function ($scanners): bool {
            foreach ((array) $scanners as $scanner) {
                if (($scanner['outcome'] ?? 'ok') !== 'ok') {
                    return true;
                }
            }

            return false;
        })->count();

        $rate = new FailureRate($total, $degraded, (int) $config['min_samples']);

        $result = Result::make()
            ->meta([
                'total' => $rate->total,
                'degraded' => $degraded,
                'percent' => $rate->percent(),
                'window_hours' => (int) $config['window_hours'],
            ])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if ($rate->belowFloor()) {
            return $result->ok();
        }

        if ($rate->percent() > (int) $config['fail_percent']) {
            return $result->failed(
                "{$degraded} of {$rate->total} audit runs in the last {$config['window_hours']}h had a scanner that did not complete."
            );
        }

        return $result->ok();
    }
}
