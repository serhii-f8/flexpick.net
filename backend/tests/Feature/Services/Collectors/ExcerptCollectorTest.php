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
    /** The exact flags Anthropic\\Core\\Util::JSON_ENCODE_FLAGS uses for the request body. */
    private const SDK_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
            app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
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

    private function contentFor(string $path): string
    {
        foreach (app(ExcerptCollector::class)->collect($this->context())['excerpts'] as $excerpt) {
            if ($excerpt['path'] === $path) {
                return $excerpt['content'];
            }
        }

        $this->fail("No excerpt collected for {$path}");
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
            app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
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
        config()->set('audit.tiers.diagnostic.excerpt_files', 2);

        $paths = $this->paths($this->context());

        $this->assertCount(2, $paths);
        $this->assertSame(['app/Legacy.php', 'app/User.php'], $paths);
    }

    /**
     * The SDK encodes the whole request body with JSON_THROW_ON_ERROR, so a
     * single excerpt byte that isn't valid UTF-8 kills the analysis call
     * with "Malformed UTF-8 characters, possibly incorrectly encoded" — a
     * failure that reads like an AI outage but is really a file we read.
     * Audited repos are arbitrary: a Latin-1 source file is normal, not
     * exotic.
     */
    public function test_excerpts_from_a_non_utf8_file_can_still_be_encoded(): void
    {
        File::put($this->repo.'/app/Legacy.php', "<?php\n// r\xE9sum\xE9 parser\n");

        $content = $this->contentFor('app/Legacy.php');

        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $content], self::SDK_FLAGS));
    }

    /**
     * The subtler half: excerptBytes caps the read in BYTES, so a perfectly
     * valid UTF-8 file gets sliced mid-codepoint whenever a multi-byte
     * character straddles the cap. No unusual encoding needed — one emoji or
     * curly quote at the wrong offset is enough.
     */
    public function test_excerpts_truncated_mid_codepoint_can_still_be_encoded(): void
    {
        $raw = "<?php\n// caf\u{e9} \u{2615}\n";
        File::put($this->repo.'/app/Legacy.php', $raw);

        // One byte into the three-byte emoji.
        config()->set('audit.tiers.diagnostic.excerpt_bytes', strpos($raw, "\u{2615}") + 1);

        $content = $this->contentFor('app/Legacy.php');

        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $content], self::SDK_FLAGS));
    }
}
