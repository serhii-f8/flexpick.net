<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\MetricsCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class MetricsCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/metrics-collector-repo-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/app', 0755, true);

        file_put_contents($this->repo.'/composer.json', json_encode([
            'require' => ['laravel/framework' => '^13.0'],
        ]));
        file_put_contents($this->repo.'/app/Service.php', str_repeat("<?php // line\n", 50));

        exec('git -C '.escapeshellarg($this->repo).' init -q 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($this->repo).' config user.name Test');
        exec('git -C '.escapeshellarg($this->repo).' add -A 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' commit -q -m init 2>&1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->repo));

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        $context = new RepoContext(
            path: $this->repo,
            tier: app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
        );

        $context->withInventory(new SccInventory(
            files: [['path' => 'app/Service.php', 'loc' => 50, 'complexity' => 3]],
            languages: ['PHP' => ['files' => 1, 'loc' => 50]],
            totalLoc: 50,
            totalComplexity: 3,
        ));

        return $context;
    }

    public function test_composes_collector_output_under_named_keys(): void
    {
        $collected = app(MetricsCollector::class)->collect($this->context());
        $metrics = $collected['metrics'];

        $this->assertArrayHasKey('git', $metrics);
        $this->assertArrayHasKey('manifests', $metrics);
        $this->assertArrayHasKey('tooling', $metrics);
        $this->assertArrayHasKey('hotspots', $metrics);
        $this->assertArrayHasKey('files_total', $metrics);
        $this->assertArrayHasKey('loc_total', $metrics);
    }

    public function test_excerpts_are_returned_separately_from_metrics(): void
    {
        $collected = app(MetricsCollector::class)->collect($this->context());

        $this->assertArrayNotHasKey('excerpts', $collected['metrics']);
        $this->assertIsArray($collected['excerpts']);
    }

    public function test_superseded_keys_are_gone(): void
    {
        $metrics = app(MetricsCollector::class)->collect($this->context())['metrics'];

        $this->assertArrayNotHasKey('secret_findings', $metrics);
        $this->assertArrayNotHasKey('duplication_pct', $metrics);
    }
}
