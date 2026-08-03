<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\MetricsCollector;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class MetricsCollectorGitTest extends FeatureTest
{
    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoPath = storage_path('framework/testing/git-insights-'.uniqid());
        File::ensureDirectoryExists($this->repoPath);

        Process::path($this->repoPath)->run(['git', 'init', '-q']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.email', 'a@example.com']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.name', 'A']);

        File::put($this->repoPath.'/churny.php', "<?php\n// v1 padding padding padding\n");
        File::put($this->repoPath.'/stable.php', "<?php\n// stable padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'add', '.']);
        Process::path($this->repoPath)->run(['git', 'commit', '-qm', 'c1']);

        File::put($this->repoPath.'/churny.php', "<?php\n// v2 padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'commit', '-aqm', 'c2']);

        Process::path($this->repoPath)->run(['git', 'config', 'user.email', 'b@example.com']);
        Process::path($this->repoPath)->run(['git', 'config', 'user.name', 'B']);
        File::put($this->repoPath.'/churny.php', "<?php\n// v3 padding padding padding\n");
        Process::path($this->repoPath)->run(['git', 'commit', '-aqm', 'c3']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    public function test_collects_contributor_stats_and_hotspots(): void
    {
        $metrics = app(MetricsCollector::class)->collect($this->repoPath, app(TierProfileResolver::class)->for(AuditTier::AUTOMATED))['metrics'];

        $this->assertSame(3, $metrics['git']['commits_analyzed']);
        $this->assertSame(2, $metrics['git']['contributors']);
        $this->assertSame(67, $metrics['git']['top_contributor_pct']); // 2 of 3 commits

        $this->assertNotEmpty($metrics['hotspots']);
        $this->assertSame('churny.php', $metrics['hotspots'][0]['path']);
        $this->assertSame(3, $metrics['hotspots'][0]['changes']);
        $this->assertArrayNotHasKey('stable.php', array_column($metrics['hotspots'], 'changes', 'path')); // only 1 change
    }
}
