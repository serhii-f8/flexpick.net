<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Services\AuditReport\Collectors\ExcerptCollector;
use App\Services\AuditReport\PromptComposer;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

/**
 * Guards the seam the collector tests cannot see.
 *
 * ExcerptCollectorTest proves one excerpt is clean; what actually reaches
 * Anthropic is the whole composed prompt, and it is encoded in one shot with
 * JSON_THROW_ON_ERROR (Anthropic\Core\Util::JSON_ENCODE_FLAGS). A future
 * prompt section that interpolates repo bytes some other way would leave every
 * collector test green and still abort the run, so the assertion belongs here,
 * on the string that is actually sent.
 */
class AuditPromptEncodingTest extends FeatureTest
{
    /** The exact flags the SDK encodes the request body with. */
    private const SDK_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/prompt-encoding-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/src');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    /**
     * The repo that exposed this in production is a Hebrew learning app, and
     * Hebrew is two bytes per character in UTF-8. Across a byte cap, roughly a
     * fifth of offsets land mid-character, so with 50 excerpt files a
     * mid-codepoint slice is not a risk — it is a certainty.
     */
    public function test_a_prompt_built_from_dense_hebrew_source_can_be_encoded(): void
    {
        File::put(
            $this->repo.'/src/mockText.ts',
            str_repeat("export const parasha = \"בראשית ברא אלהים\";\n", 400),
        );

        $prompt = $this->composeFor('src/mockText.ts', $this->midCodepointCap('src/mockText.ts'));

        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $prompt], self::SDK_FLAGS));
    }

    public function test_a_prompt_built_from_a_non_utf8_source_file_can_be_encoded(): void
    {
        File::put($this->repo.'/src/legacy.ts', "// r\xE9sum\xE9 parser\nexport const x = 1;\n");

        $prompt = $this->composeFor('src/legacy.ts');

        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $prompt], self::SDK_FLAGS));
    }

    /**
     * Search down from the configured cap for a byte offset that genuinely
     * splits a character. Picking an offset by arithmetic silently lands on a
     * clean boundary about four times in five, which would leave this test
     * passing against the unfixed reader.
     */
    private function midCodepointCap(string $path): int
    {
        $raw = (string) file_get_contents($this->repo.'/'.$path);

        for ($n = (int) config('audit.tiers.diagnostic.excerpt_bytes'); $n > 0; $n--) {
            if (! mb_check_encoding(substr($raw, 0, $n), 'UTF-8')) {
                return $n;
            }
        }

        $this->fail("No mid-codepoint byte offset exists in {$path}");
    }

    private function composeFor(string $path, ?int $cap = null): string
    {
        if ($cap !== null) {
            config()->set('audit.tiers.diagnostic.excerpt_bytes', $cap);
        }

        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
            new SccInventory(
                files: [['path' => $path, 'loc' => 400, 'complexity' => 1]],
                languages: [],
                totalLoc: 400,
                totalComplexity: 1,
            ),
        );

        $excerpts = app(ExcerptCollector::class)->collect($context)['excerpts'];
        $this->assertNotEmpty($excerpts);

        return app(PromptComposer::class)->compose(['loc_total' => 400], [], $excerpts);
    }
}
