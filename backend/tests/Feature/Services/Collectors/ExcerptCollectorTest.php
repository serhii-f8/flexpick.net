<?php

namespace Tests\Feature\Services\Collectors;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class ExcerptCollectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/excerpt-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app');
        File::put($this->repo.'/.env', "APP_KEY=base64:supersecret\n");
        File::put($this->repo.'/app/User.php', "<?php\nclass User {}\n");
        File::put($this->repo.'/app/Legacy.php', "<?php\nconst TOKEN = 'sk-live-abc';\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(array $secretPaths = []): RepoContext
    {
        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
            new SccInventory(
                files: [
                    ['path' => '.env', 'loc' => 40, 'complexity' => 0],
                    ['path' => 'app/Legacy.php', 'loc' => 20, 'complexity' => 1],
                    ['path' => 'app/User.php', 'loc' => 10, 'complexity' => 1],
                ],
                languages: [],
                totalLoc: 70,
                totalComplexity: 2,
            ),
        );

        $context->withSecretPaths($secretPaths);

        return $context;
    }

    private function paths(RepoContext $context): array
    {
        return array_column(app(ExcerptCollector::class)->collect($context)['excerpts'], 'path');
    }

    public function test_denylisted_files_never_reach_the_model(): void
    {
        $this->assertNotContains('.env', $this->paths($this->context()));
    }

    public function test_gitleaks_flagged_files_never_reach_the_model(): void
    {
        $paths = $this->paths($this->context(['app/Legacy.php']));

        $this->assertNotContains('app/Legacy.php', $paths);
        $this->assertContains('app/User.php', $paths);
    }

    public function test_ordinary_files_are_still_collected(): void
    {
        $this->assertContains('app/User.php', $this->paths($this->context()));
    }

    /**
     * The seam this file previously hand-waved: every test above builds the
     * inventory from literal relative paths, so none of them exercised what
     * scc actually emits. scc is invoked with the absolute clone path and
     * echoes it back, and joining that onto the clone root again resolves to
     * nothing — the collector silently returned zero excerpts on every real
     * run while the whole suite stayed green.
     */
    public function test_collects_excerpts_from_a_real_scc_inventory(): void
    {
        $inventory = app(SccScanner::class)->toInventory([[
            'Name' => 'PHP',
            'Files' => [
                ['Location' => $this->repo.'/app/User.php', 'Lines' => 10, 'Complexity' => 1],
                ['Location' => $this->repo.'/app/Legacy.php', 'Lines' => 20, 'Complexity' => 1],
            ],
        ]], $this->repo);

        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::AUTOMATED),
            $inventory,
        );

        $excerpts = app(ExcerptCollector::class)->collect($context)['excerpts'];

        $this->assertCount(2, $excerpts);
        $this->assertSame('app/Legacy.php', $excerpts[0]['path']);
        $this->assertStringContainsString('class User', $excerpts[1]['content']);
    }

    /**
     * Proves the loop-restructure, not just the filter. A naive
     * slice-then-filter implementation — array_slice($files, 0,
     * excerptFiles) followed by an exclusion check inside the loop — would
     * slice to ['.env', 'app/Legacy.php'] for a budget of 2, exclude '.env',
     * and return only 1 excerpt: the excluded file's slot would be dropped,
     * not backfilled. Breaking on the collected count instead keeps walking
     * the inventory past the excluded file until the budget is actually met.
     */
    public function test_excluded_files_slot_is_backfilled_not_dropped(): void
    {
        config()->set('audit.tiers.automated.excerpt_files', 2);

        $paths = $this->paths($this->context());

        $this->assertCount(2, $paths);
        $this->assertSame(['app/Legacy.php', 'app/User.php'], $paths);
    }
}
