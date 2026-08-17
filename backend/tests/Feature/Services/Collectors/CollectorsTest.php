<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\Collector;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\Collectors\GitFactsCollector;
use App\Services\AuditReport\Collectors\HotspotCollector;
use App\Services\AuditReport\Collectors\ManifestCollector;
use App\Services\AuditReport\Collectors\ToolingCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Tests\Feature\FeatureTest;

class CollectorsTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/collector-repo-'.bin2hex(random_bytes(6));
        mkdir($this->repo.'/app', 0755, true);

        file_put_contents($this->repo.'/composer.json', json_encode([
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['phpstan/phpstan' => '^2.0', 'laravel/pint' => '^1.0'],
        ]));
        file_put_contents($this->repo.'/composer.lock', '{}');
        file_put_contents($this->repo.'/.env.example', 'APP_KEY=');
        file_put_contents($this->repo.'/app/Service.php', str_repeat("<?php // line\n", 50));
        file_put_contents($this->repo.'/churny.php', "<?php\n// v1 padding padding padding\n");
        file_put_contents($this->repo.'/stable.php', "<?php\n// stable padding padding padding\n");

        exec('git -C '.escapeshellarg($this->repo).' init -q 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($this->repo).' config user.name Test');
        exec('git -C '.escapeshellarg($this->repo).' add -A 2>&1');
        exec('git -C '.escapeshellarg($this->repo).' commit -q -m init 2>&1');

        // Second commit, same author — still churns churny.php.
        file_put_contents($this->repo.'/churny.php', "<?php\n// v2 padding padding padding\n");
        exec('git -C '.escapeshellarg($this->repo).' commit -aqm c2 2>&1');

        // Third commit, a different author — makes contributors() = 2.
        exec('git -C '.escapeshellarg($this->repo).' config user.email b@example.com');
        exec('git -C '.escapeshellarg($this->repo).' config user.name B');
        file_put_contents($this->repo.'/churny.php', "<?php\n// v3 padding padding padding\n");
        exec('git -C '.escapeshellarg($this->repo).' commit -aqm c3 2>&1');
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
            files: [
                ['path' => 'app/Service.php', 'loc' => 50, 'complexity' => 3],
                ['path' => 'churny.php', 'loc' => 2, 'complexity' => 0],
                ['path' => 'stable.php', 'loc' => 2, 'complexity' => 0],
            ],
            languages: ['PHP' => ['files' => 3, 'loc' => 54]],
            totalLoc: 54,
            totalComplexity: 3,
        ));

        return $context;
    }

    public function test_every_collector_implements_the_interface(): void
    {
        foreach ([GitFactsCollector::class, ManifestCollector::class, ToolingCollector::class,
            HotspotCollector::class, ExcerptCollector::class] as $class) {
            $this->assertInstanceOf(Collector::class, app($class));
        }
    }

    public function test_git_facts_collector_reports_branch_and_contributors(): void
    {
        $facts = app(GitFactsCollector::class)->collect($this->context());

        $this->assertArrayHasKey('default_branch', $facts);
        $this->assertSame(3, $facts['commits_analyzed']);
        $this->assertSame(2, $facts['contributors']);
        $this->assertSame(67, $facts['top_contributor_pct']); // 2 of 3 commits
    }

    public function test_manifest_collector_counts_dependencies_and_detects_lockfiles(): void
    {
        $manifests = app(ManifestCollector::class)->collect($this->context());

        $this->assertSame(1, $manifests['composer.json']['dependencies']);
        $this->assertSame(2, $manifests['composer.json']['dev_dependencies']);
        $this->assertTrue($manifests['composer.json']['lockfile']);
        $this->assertFalse($manifests['composer.json']['parse_error']);
    }

    public function test_tooling_collector_detects_static_analysis_and_env_example(): void
    {
        $tooling = app(ToolingCollector::class)->collect($this->context());

        $this->assertTrue($tooling['static_analysis']);
        $this->assertTrue($tooling['linter']);
        $this->assertTrue($tooling['env_example']);
        $this->assertFalse($tooling['dockerized']);
    }

    public function test_excerpt_collector_respects_the_tier_budget(): void
    {
        config()->set('audit.tiers.automated.excerpt_files', 1);
        config()->set('audit.tiers.automated.excerpt_bytes', 20);

        $excerpts = app(ExcerptCollector::class)->collect($this->context())['excerpts'];

        $this->assertCount(1, $excerpts);
        $this->assertLessThanOrEqual(20, strlen($excerpts[0]['content']));
    }

    public function test_excerpt_collector_selects_from_the_scc_inventory(): void
    {
        $excerpts = app(ExcerptCollector::class)->collect($this->context())['excerpts'];

        $this->assertSame('app/Service.php', $excerpts[0]['path']);
    }

    public function test_manifest_collector_flags_unparseable_composer_json(): void
    {
        // Overwrite the valid composer.json with truncated/corrupt JSON.
        file_put_contents($this->repo.'/composer.json', '{"require": {"laravel/framework": "^13.0"');
        exec('git -C '.escapeshellarg($this->repo).' commit -aqm corrupt 2>&1');

        $manifests = app(ManifestCollector::class)->collect($this->context());

        $this->assertArrayHasKey('composer.json', $manifests);
        $this->assertTrue($manifests['composer.json']['parse_error']);
        $this->assertSame(0, $manifests['composer.json']['dependencies']);
        $this->assertSame(0, $manifests['composer.json']['dev_dependencies']);
    }

    public function test_manifest_collector_flags_unparseable_package_json(): void
    {
        // Write a package.json with a trailing comma (common JS-style comment issue).
        file_put_contents($this->repo.'/package.json', '{"dependencies": {"vue": "^3.0",}}');
        exec('git -C '.escapeshellarg($this->repo).' add -A && git -C '.escapeshellarg($this->repo).' commit -qm pkg 2>&1');

        $manifests = app(ManifestCollector::class)->collect($this->context());

        $this->assertArrayHasKey('package.json', $manifests);
        $this->assertTrue($manifests['package.json']['parse_error']);
        $this->assertSame(0, $manifests['package.json']['dependencies']);
        $this->assertSame(0, $manifests['package.json']['dev_dependencies']);
    }

    public function test_hotspot_collector_ranks_churned_files_above_stable_ones(): void
    {
        $hotspots = app(HotspotCollector::class)->collect($this->context());

        $this->assertNotEmpty($hotspots);
        $this->assertSame('churny.php', $hotspots[0]['path']);
        $this->assertSame(3, $hotspots[0]['changes']);
        // stable.php changed only once — below the >=2 threshold.
        $this->assertArrayNotHasKey('stable.php', array_column($hotspots, 'changes', 'path'));
    }
}
