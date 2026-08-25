<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\MetricsCollector;
use App\Services\AuditReport\PromptComposer;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

/**
 * File excerpts were only the entry point we happened to hit first.
 *
 * Everything the pipeline learns about a repo is bytes we did not write, and
 * an audited repo is entitled to contain any bytes at all — a branch name in
 * Latin-1, a file committed under a Windows-1252 name, a commit author string
 * from a decade-old client. Each of those reaches a layer that treats invalid
 * UTF-8 as fatal rather than as data.
 */
class AuditEncodingBoundaryTest extends FeatureTest
{
    /** The exact flags the Anthropic SDK encodes the request body with. */
    private const SDK_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/encoding-boundary-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo);
        File::put($this->repo.'/index.ts', "export const x = 1;\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    /**
     * $metrics is persisted through an Eloquent array cast BEFORE the model is
     * ever called, so a bad byte here does not merely spoil the prompt — it
     * aborts the run one stage earlier, with a different error, and after the
     * clone has already been paid for.
     */
    public function test_metrics_from_a_repo_with_non_utf8_git_metadata_can_be_persisted(): void
    {
        $this->fakeGitWithLatin1Metadata();

        $metrics = app(MetricsCollector::class)->collect($this->context())['metrics'];

        $request = AuditRequest::factory()->create();

        // The claim is that this update does not throw JsonEncodingException,
        // and that what lands in the column is the scrubbed branch name --
        // not that the array survives a JSON round trip byte-identically,
        // which it never does (float 0.0 returns as int 0, keys reorder).
        $request->update(['metrics' => $metrics]);

        $stored = $request->fresh()->metrics;

        $this->assertTrue(mb_check_encoding($stored['git']['default_branch'], 'UTF-8'));
        $this->assertSame("feature/caf\u{FFFD}-refactor", $stored['git']['default_branch']);
        $this->assertIsString(json_encode($stored, self::SDK_FLAGS));

        // scc reports the file name and `git log --name-only` reports it
        // again; both arrive as raw bytes and both are scrubbed. If the two
        // sides scrubbed differently the paths would stop matching and the
        // hotspot would vanish silently -- a worse failure than a crash.
        $this->assertContains("src/caf\u{FFFD}.ts", array_column($stored['hotspots'], 'path'));
    }

    public function test_a_prompt_built_from_non_utf8_git_metadata_can_be_encoded(): void
    {
        $this->fakeGitWithLatin1Metadata();

        $collected = app(MetricsCollector::class)->collect($this->context());
        $prompt = app(PromptComposer::class)->compose($collected['metrics'], [], $collected['excerpts']);

        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $prompt], self::SDK_FLAGS));
    }

    /**
     * Groups come from scanner output, which is decoded before we see it — but
     * the composer is the last thing standing between arbitrary repo bytes and
     * an encode that throws, so it must hold even when its inputs did not.
     */
    public function test_the_composer_survives_group_data_carrying_raw_bytes(): void
    {
        $prompt = app(PromptComposer::class)->compose(
            ['loc_total' => 1],
            [new FindingGroup(
                ruleFamily: "r\xE9sum\xE9-handling",
                directory: "src/caf\xE9",
                severity: Severity::HIGH,
                count: 2,
                score: 40,
                examples: [['path' => "src/caf\xE9.ts", 'line' => 12]],
                tools: ['semgrep'],
                dimension: 'security_hygiene',
            )],
            [['path' => "src/caf\xE9.ts", 'content' => "const x = \xE9;\n"]],
        );

        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
        $this->assertIsString(json_encode(['content' => $prompt], self::SDK_FLAGS));
    }

    /**
     * Branch names and author identities are free-form bytes in git. `git log
     * --name-only` hands back file names the same way, so a file committed
     * under a Latin-1 name arrives here byte-for-byte.
     */
    private function fakeGitWithLatin1Metadata(): void
    {
        Process::fake([
            '*rev-parse*' => Process::result("feature/caf\xE9-refactor\n"),
            '*%cI*' => Process::result("2026-08-25T10:00:00+00:00\n"),
            '*%ae*' => Process::result("ren\xE9@example.com\nren\xE9@example.com\n"),
            '*name-only*' => Process::result("src/caf\xE9.ts\nsrc/caf\xE9.ts\nindex.ts\n"),
            '*' => Process::result(''),
        ]);
    }

    private function context(): RepoContext
    {
        return new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DIAGNOSTIC),
            // scc has already been through the scanner boundary by this point,
            // so its inventory carries the scrubbed spelling of the name.
            new SccInventory(
                files: [['path' => "src/caf\u{FFFD}.ts", 'loc' => 40, 'complexity' => 2]],
                languages: [],
                totalLoc: 40,
                totalComplexity: 2,
            ),
        );
    }
}
