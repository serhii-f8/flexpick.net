<?php

namespace Tests\Feature\Services;

use App\Exceptions\AuditNotAnalyzableException;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class RepositoryClonerTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_clones_a_reachable_repo_shallow(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-'.uniqid();

        $cloner->preflight('file://'.$this->fixtureRepo);
        $path = $cloner->clone('file://'.$this->fixtureRepo, $uuid);

        $this->assertFileExists($path.'/README.md');
        $log = Process::path($path)->run('git rev-list --count HEAD')->throw();
        $this->assertSame('1', trim($log->output())); // depth 1

        $cloner->cleanup($uuid);
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_preflight_rejects_unreachable_repo(): void
    {
        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->preflight('file:///nonexistent/definitely-not-a-repo');
    }

    public function test_cleanup_is_idempotent(): void
    {
        app(RepositoryCloner::class)->cleanup('never-existed');
        $this->assertTrue(true);
    }

    public function test_preflight_exception_message_redacts_url_credentials(): void
    {
        try {
            app(RepositoryCloner::class)->preflight('https://user:secrettoken@nonexistent.invalid/org/repo.git');
            $this->fail('Expected AuditNotAnalyzableException was not thrown.');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('secrettoken', $e->getMessage());
            $this->assertStringNotContainsString('user:secrettoken@', $e->getMessage());
            $this->assertStringContainsString('nonexistent.invalid', $e->getMessage());
        }
    }

    private string $branchFixtureRepo;

    private function branchFixtureRepo(): string
    {
        if (isset($this->branchFixtureRepo)) {
            return $this->branchFixtureRepo;
        }

        $this->branchFixtureRepo = storage_path('framework/testing/fixture-repo-branches');

        if (! File::isDirectory($this->branchFixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->branchFixtureRepo);
            File::put($this->branchFixtureRepo.'/README.md', "# Fixture\n");
            Process::path($this->branchFixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
            Process::path($this->branchFixtureRepo)->run('git checkout -qb feature-branch')->throw();
            File::put($this->branchFixtureRepo.'/FEATURE.md', "# Feature\n");
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->branchFixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm feature')->throw();
            Process::path($this->branchFixtureRepo)->run('git checkout -q main')->throw();
        }

        return $this->branchFixtureRepo;
    }

    public function test_clone_with_no_branch_uses_the_default_branch(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-default-'.uniqid();

        $path = $cloner->clone('file://'.$this->branchFixtureRepo(), $uuid);

        $this->assertFileExists($path.'/README.md');
        $this->assertFileDoesNotExist($path.'/FEATURE.md');
        $cloner->cleanup($uuid);
    }

    public function test_clone_with_a_branch_checks_out_that_branch(): void
    {
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-clone-branch-'.uniqid();

        $path = $cloner->clone('file://'.$this->branchFixtureRepo(), $uuid, 'feature-branch');

        $this->assertFileExists($path.'/FEATURE.md');
        $cloner->cleanup($uuid);
    }

    public function test_clone_with_a_nonexistent_branch_throws(): void
    {
        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->clone('file://'.$this->branchFixtureRepo(), 'test-clone-missing-'.uniqid(), 'does-not-exist');
    }

    public function test_remote_head_sha_returns_the_resolved_sha(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\tHEAD\n")]);

        $this->assertSame('abc123', app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app'));
    }

    public function test_remote_head_sha_returns_null_on_failure(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->assertNull(app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app'));
    }

    public function test_remote_head_sha_targets_the_given_branch_ref(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\trefs/heads/develop\n")]);

        app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/app', 'develop');

        Process::assertRan(fn (PendingProcess $process) => in_array('refs/heads/develop', $process->command, true));
    }
}
