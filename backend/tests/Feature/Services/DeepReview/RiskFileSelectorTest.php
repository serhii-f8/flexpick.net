<?php

namespace Tests\Feature\Services\DeepReview;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class RiskFileSelectorTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/risk-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app/Auth');
        File::ensureDirectoryExists($this->repo.'/app/Models');
        File::ensureDirectoryExists($this->repo.'/vendor/acme');

        File::put($this->repo.'/app/Auth/Guard.php', "<?php\n// guard\n");
        File::put($this->repo.'/app/Models/Post.php', "<?php\n// post\n");
        File::put($this->repo.'/app/Models/Comment.php', "<?php\n// comment\n");
        File::put($this->repo.'/vendor/acme/Lib.php', "<?php\n// vendored\n");
        File::put($this->repo.'/.env', "SECRET=1\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    /** @param array<string, int> $churn */
    private function context(array $churn = [], array $secretPaths = []): RepoContext
    {
        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(
                files: [
                    ['path' => 'app/Auth/Guard.php', 'loc' => 100, 'complexity' => 5],
                    ['path' => 'app/Models/Post.php', 'loc' => 80, 'complexity' => 3],
                    ['path' => 'app/Models/Comment.php', 'loc' => 60, 'complexity' => 2],
                    ['path' => 'vendor/acme/Lib.php', 'loc' => 900, 'complexity' => 40],
                    ['path' => '.env', 'loc' => 30, 'complexity' => 0],
                ],
                languages: [],
                totalLoc: 1170,
                totalComplexity: 50,
            ),
        );

        $context->withChurn($churn);
        $context->withSecretPaths($secretPaths);

        return $context;
    }

    private function finding(string $path, Severity $severity = Severity::HIGH): DedupedFinding
    {
        return new DedupedFinding(
            new Finding(
                tool: 'semgrep',
                ruleId: 'r1',
                ruleFamily: 'security.injection',
                severity: $severity,
                path: $path,
                line: 1,
                message: 'rule description',
                dimension: 'security_hygiene',
            ),
            ['semgrep'],
        );
    }

    private function profile()
    {
        return app(TierProfileResolver::class)->for(AuditTier::DEEP_AI)->deepReview;
    }

    private function select(RepoContext $context, array $findings = [])
    {
        return app(RiskFileSelector::class)->select($context, $findings, $this->profile());
    }

    public function test_vendored_and_secret_files_are_never_candidates(): void
    {
        $paths = $this->select($this->context(['vendor/acme/Lib.php' => 50]))->paths();

        $this->assertNotContains('vendor/acme/Lib.php', $paths);
        $this->assertNotContains('.env', $paths);
    }

    public function test_a_consensus_file_outranks_single_signal_files(): void
    {
        // Guard.php: churn + findings + sensitive. Post.php: churn only.
        $selection = $this->select(
            $this->context(['app/Auth/Guard.php' => 5, 'app/Models/Post.php' => 9]),
            [$this->finding('app/Auth/Guard.php', Severity::CRITICAL)],
        );

        $this->assertSame('app/Auth/Guard.php', $selection->files[0]->path);
    }

    public function test_a_zero_signal_normalizes_to_zero(): void
    {
        // Comment.php has no findings at all; its finding-density signal must
        // be exactly 0, not a percentile inflated by all the other zeroes.
        $selection = $this->select(
            $this->context(['app/Models/Comment.php' => 2]),
            [$this->finding('app/Models/Post.php')],
        );

        $comment = collect($selection->files)->firstWhere('path', 'app/Models/Comment.php');

        $this->assertSame(0.0, $comment->signals['findings']['normalized']);
    }

    public function test_selection_is_deterministic(): void
    {
        $run = fn () => $this->select(
            $this->context(['app/Auth/Guard.php' => 3, 'app/Models/Post.php' => 3]),
            [$this->finding('app/Models/Post.php')],
        )->paths();

        $this->assertSame($run(), $run());
    }

    public function test_ties_break_by_path_ascending(): void
    {
        // No churn, no findings, no sensitive paths — every score is 0.
        $paths = $this->select($this->context())->paths();

        $sorted = $paths;
        sort($sorted);

        $this->assertSame($sorted, $paths);
    }

    public function test_every_selected_file_records_its_signal_contributions(): void
    {
        $file = $this->select($this->context(['app/Auth/Guard.php' => 4]))->files[0];

        foreach (['churn', 'findings', 'sensitive'] as $signal) {
            $this->assertArrayHasKey('raw', $file->signals[$signal]);
            $this->assertArrayHasKey('normalized', $file->signals[$signal]);
        }
    }

    public function test_the_log_array_never_carries_file_content(): void
    {
        $logged = $this->select($this->context(['app/Auth/Guard.php' => 4]))->toLogArray();

        $this->assertStringNotContainsString('guard', json_encode($logged));
        $this->assertSame(1, $logged['selection_version']);
    }
}
