<?php

namespace App\Services\AuditReport;

use App\Models\AuditReport;
use Illuminate\Support\Facades\Cache;

class AuditBenchmarkService
{
    public function percentileFor(int $overallScore, ?int $scoringVersion = null): ?int
    {
        $version = $scoringVersion ?? ScoreCalculator::VERSION;

        $scores = Cache::remember("audit-benchmark-overall-scores:v{$version}", 3600, function () use ($version): array {
            return AuditReport::query()
                ->where('scoring_version', $version)
                ->pluck('payload')
                ->map(function ($payload): ?int {
                    $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

                    return isset($decoded['scores']['overall']) ? (int) $decoded['scores']['overall'] : null;
                })
                ->filter(fn (?int $score): bool => $score !== null)
                ->values()
                ->all();
        });

        if (count($scores) < (int) config('audit.benchmark_min_sample')) {
            return null;
        }

        $below = count(array_filter($scores, fn (int $score): bool => $score < $overallScore));

        return (int) round(100 * $below / count($scores));
    }
}
