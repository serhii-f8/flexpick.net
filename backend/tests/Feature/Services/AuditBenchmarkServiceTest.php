<?php

namespace Tests\Feature\Services;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditBenchmarkService;
use App\Services\AuditReport\ScoreCalculator;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\FeatureTest;

class AuditBenchmarkServiceTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        AuditReport::query()->delete();
    }

    private function seedScores(array $scores): void
    {
        foreach ($scores as $score) {
            $payload = AuditReport::factory()->raw()['payload'];
            $payload['scores']['overall'] = $score;
            // percentileFor() defaults to the current ScoreCalculator::VERSION;
            // these rows must match it or the pooling query excludes them.
            AuditReport::factory()->create(['payload' => $payload, 'scoring_version' => ScoreCalculator::VERSION]);
        }
    }

    public function test_returns_null_below_min_sample(): void
    {
        config(['audit.benchmark_min_sample' => 5]);
        $this->seedScores([10, 20, 30]);

        $this->assertNull(app(AuditBenchmarkService::class)->percentileFor(25));
    }

    public function test_computes_percentile(): void
    {
        config(['audit.benchmark_min_sample' => 4]);
        $this->seedScores([10, 20, 30, 40]);

        // 2 of 4 scores are below 25 → 50th percentile
        $this->assertSame(50, app(AuditBenchmarkService::class)->percentileFor(25));
    }

    public function test_pools_only_reports_sharing_the_current_scoring_version(): void
    {
        // 25 v1 reports would otherwise satisfy benchmark_min_sample and produce a percentile.
        AuditReport::factory()->count(25)->create([
            'payload' => ['scores' => ['overall' => 50]],
            'scoring_version' => 1,
        ]);

        Cache::flush();

        $this->assertNull(app(AuditBenchmarkService::class)->percentileFor(80, scoringVersion: 2));
    }
}
