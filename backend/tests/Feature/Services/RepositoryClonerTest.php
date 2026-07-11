<?php

namespace Tests\Feature\Services;

use App\Exceptions\AuditNotAnalyzableException;
use App\Services\AuditReport\RepositoryCloner;
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
}
