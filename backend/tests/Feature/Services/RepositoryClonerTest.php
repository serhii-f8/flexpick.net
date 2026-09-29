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

        $this->discardFixtureNotOwnedByUs($this->fixtureRepo);

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
     * git runs with GIT_CONFIG_NOSYSTEM, so a system-level safe.directory can no longer
     * excuse a fixture left behind by another user (e.g. host runs vs. the root container);
     * rebuild it as ourselves.
     */
    private function discardFixtureNotOwnedByUs(string $path): void
    {
        if (File::isDirectory($path) && fileowner($path) !== posix_geteuid()) {
            File::deleteDirectory($path);
        }
    }

    public function test_clones_a_reachable_repo_shallow(): void
    {
        // The shared fixture may carry extra commits from other suites' helpers.
        config(['audit.clone_depth' => 1]);
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

        $this->discardFixtureNotOwnedByUs($this->branchFixtureRepo);

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

        $path = $cloner->clone('file://'.$this->branchFixtureRepo(), $uuid, branch: 'feature-branch');

        $this->assertFileExists($path.'/FEATURE.md');
        $cloner->cleanup($uuid);
    }

    public function test_clone_with_a_nonexistent_branch_throws(): void
    {
        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->clone('file://'.$this->branchFixtureRepo(), 'test-clone-missing-'.uniqid(), branch: 'does-not-exist');
    }

    /** A repo whose working tree is tiny but whose history holds a 3 MB blob. */
    private function repoWithBlobOnlyInHistory(): string
    {
        $repo = storage_path('framework/testing/history-heavy-repo-'.uniqid());
        File::ensureDirectoryExists($repo);
        File::put($repo.'/blob.bin', random_bytes(3 * 1024 * 1024));
        File::put($repo.'/index.php', "<?php\n");
        $git = 'git -c user.email=t@t -c user.name=t';
        Process::path($repo)->run('git init -q -b main')->throw();
        Process::path($repo)->run("$git add -A")->throw();
        Process::path($repo)->run("$git commit -qm heavy")->throw();
        Process::path($repo)->run('git rm -q blob.bin')->throw();
        Process::path($repo)->run("$git commit -qm slim")->throw();

        return $repo;
    }

    public function test_the_size_cap_ignores_git_history(): void
    {
        config(['audit.max_repo_size_mb' => 2, 'audit.clone_depth' => 200]);
        $repo = $this->repoWithBlobOnlyInHistory();
        $cloner = app(RepositoryCloner::class);
        $uuid = 'test-size-history-'.uniqid();

        $path = $cloner->clone('file://'.$repo, $uuid);

        $this->assertFileExists($path.'/index.php');

        $cloner->cleanup($uuid);
        File::deleteDirectory($repo);
    }

    public function test_the_size_cap_rejects_a_heavy_working_tree(): void
    {
        config(['audit.max_repo_size_mb' => 2]);
        $repo = storage_path('framework/testing/heavy-tree-repo-'.uniqid());
        File::ensureDirectoryExists($repo);
        File::put($repo.'/blob.bin', random_bytes(3 * 1024 * 1024));
        $git = 'git -c user.email=t@t -c user.name=t';
        Process::path($repo)->run('git init -q -b main')->throw();
        Process::path($repo)->run("$git add -A")->throw();
        Process::path($repo)->run("$git commit -qm heavy")->throw();

        try {
            app(RepositoryCloner::class)->clone('file://'.$repo, 'test-size-heavy-'.uniqid());
            $this->fail('Expected the cap to reject the clone');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringContainsString('Repository too large for automated analysis', $e->getMessage());
        } finally {
            File::deleteDirectory($repo);
        }
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
