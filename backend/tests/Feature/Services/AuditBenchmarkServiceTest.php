<?php

namespace Tests\Feature\Services;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditBenchmarkService;
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
            AuditReport::factory()->create(['payload' => $payload]);
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
}
