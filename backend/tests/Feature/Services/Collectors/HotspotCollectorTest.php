<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\HotspotCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class HotspotCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/churn-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo);

        $git = 'git -c user.email=t@t -c user.name=t';
        Process::path($this->repo)->run('git init -q -b main')->throw();

        // hot.php changes three times, cold.php once.
        foreach (['a', 'b', 'c'] as $i => $content) {
            File::put($this->repo.'/hot.php', "<?php // {$content}\n");

            if ($i === 0) {
                File::put($this->repo.'/cold.php', "<?php\n");
            }

            Process::path($this->repo)->run("{$git} add -A")->throw();
            Process::path($this->repo)->run("{$git} commit -qm commit{$i}")->throw();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        return new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(
                files: [
                    ['path' => 'hot.php', 'loc' => 5, 'complexity' => 1],
                    ['path' => 'cold.php', 'loc' => 2, 'complexity' => 0],
                ],
                languages: [],
                totalLoc: 7,
                totalComplexity: 1,
            ),
        );
    }

    public function test_the_full_churn_map_is_recorded_on_the_context(): void
    {
        $context = $this->context();
        app(HotspotCollector::class)->collect($context);

        $this->assertSame(3, $context->churn['hot.php']);
        $this->assertSame(1, $context->churn['cold.php']);
    }

    public function test_the_returned_hotspots_still_exclude_single_change_files(): void
    {
        $context = $this->context();
        $hotspots = app(HotspotCollector::class)->collect($context);

        $this->assertSame(['hot.php'], array_column($hotspots, 'path'));
    }
}
