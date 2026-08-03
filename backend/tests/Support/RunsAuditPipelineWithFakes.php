<?php

namespace Tests\Support;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AnalysisResult;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\Scanner;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfile;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Drives the real AuditPipeline end to end with fake scanners and a fake
 * analyzer, so integration tests can assert on persisted provenance/groups/
 * telemetry without depending on the real scanner binaries or an AI call.
 */
trait RunsAuditPipelineWithFakes
{
    /** @var list<FindingGroup> */
    public array $lastAnalyzerGroups = [];

    private string $fixtureRepo;

    private function setUpAuditPipelineFixture(): void
    {
        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');

        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            File::put($this->fixtureRepo.'/index.php', "<?php\necho 'hi';\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    /**
     * @param  list<FindingGroup>  $groups  synthetic groups to make the run
     *                                      produce; realized by injecting
     *                                      matching Finding objects into one
     *                                      fake scanner so they survive real
     *                                      dedup/group logic. Callers only
     *                                      assert rule_family/count, so which
     *                                      scanner "found" them doesn't matter.
     * @param  list<string>  $failingScanners
     */
    private function runPipelineWithFakes(
        array $groups = [],
        array $failingScanners = [],
        AuditTier $tier = AuditTier::AUTOMATED,
        int $inputTokens = 10,
        int $outputTokens = 5,
    ): AuditRequest {
        $profile = app(TierProfileResolver::class)->for($tier);
        $available = array_values(array_diff($profile->scanners, $failingScanners));
        $emitter = end($available) ?: null;

        foreach ($profile->scanners as $name) {
            if (in_array($name, $failingScanners, true)) {
                $this->app->bind('audit.scanner.'.$name, fn () => $this->fakeScanner(
                    $name,
                    fn () => throw new \RuntimeException('boom'),
                ));

                continue;
            }

            if ($name === 'scc') {
                $this->app->bind('audit.scanner.scc', fn () => $this->fakeScanner('scc', function (RepoContext $ctx): array {
                    $ctx->withInventory(new SccInventory(
                        files: [['path' => 'index.php', 'loc' => 1, 'complexity' => 0]],
                        languages: ['PHP' => ['files' => 1, 'loc' => 1]],
                        totalLoc: 1,
                        totalComplexity: 0,
                    ));

                    return [];
                }));

                continue;
            }

            $findings = $name === $emitter ? $this->syntheticFindings($groups) : [];
            $this->app->bind('audit.scanner.'.$name, fn () => $this->fakeScanner($name, fn () => $findings));
        }

        $this->lastAnalyzerGroups = [];
        $this->app->instance(AiAnalyzer::class, new class($this, $inputTokens, $outputTokens) implements AiAnalyzer
        {
            public function __construct(
                private object $outer,
                private int $inputTokens,
                private int $outputTokens,
            ) {}

            public function analyze(
                array $metrics,
                array $groups,
                array $excerpts,
                TierProfile $tier,
                ?string $adminContext = null,
            ): AnalysisResult {
                $this->outer->lastAnalyzerGroups = $groups;

                return new AnalysisResult(
                    payload: [
                        'summary' => 'Fake analysis summary.',
                        'scores' => ['overall' => 50],
                        'risks' => [],
                        'fix_first_plan' => [],
                        'groups' => [],
                    ],
                    inputTokens: $this->inputTokens,
                    outputTokens: $this->outputTokens,
                );
            }
        });

        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
            'tier' => $tier->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        return $request->fresh();
    }

    private function fakeScanner(string $name, callable $scan): Scanner
    {
        return new class($name, $scan) implements Scanner
        {
            public function __construct(
                private string $scannerName,
                private $scanCallback,
            ) {}

            public function name(): string
            {
                return $this->scannerName;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function scan(RepoContext $ctx): array
            {
                return ($this->scanCallback)($ctx);
            }
        };
    }

    /**
     * @param  list<FindingGroup>  $groups
     * @return list<Finding>
     */
    private function syntheticFindings(array $groups): array
    {
        $findings = [];

        foreach ($groups as $group) {
            for ($i = 0; $i < $group->count; $i++) {
                $findings[] = new Finding(
                    tool: 'semgrep',
                    ruleId: $group->ruleFamily.'.rule',
                    ruleFamily: $group->ruleFamily,
                    severity: $group->severity,
                    path: $group->directory.'/File'.$i.'.php',
                    line: $i + 1,
                    message: 'Synthetic finding for pipeline test.',
                    dimension: $group->dimension,
                );
            }
        }

        return $findings;
    }
}
