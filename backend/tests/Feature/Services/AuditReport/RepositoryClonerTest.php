<?php

namespace Tests\Feature\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Feature\FeatureTest;

class RepositoryClonerTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRepo = base_path('tests/fixtures/sample-repo');

        // Gitignored (tests/fixtures/.gitignore), built on demand -- same
        // convention as the storage/framework/testing fixtures used by the
        // other RepositoryCloner/pipeline tests.
        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    public function test_preflight_succeeds_against_a_public_local_repo_with_no_tenant(): void
    {
        app(RepositoryCloner::class)->preflight('file://'.$this->fixtureRepo, tenant: null);

        $this->addToAssertionCount(1); // no exception thrown
    }

    /**
     * No connection is not a denial: git is handed the raw, unauthenticated URL, and
     * a private repo fails through the ordinary not-reachable path.
     */
    public function test_preflight_without_a_connection_runs_git_anonymously_and_fails_as_unreachable(): void
    {
        Process::fake(['*' => Process::result(exitCode: 128)]);
        $tenant = Tenant::factory()->create();

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertTrue($e->accessDenied);
            $this->assertStringContainsString('Repository is not publicly accessible', $e->getMessage());
        }

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'git', 'ls-remote', '--exit-code', 'https://github.com/acme/private', 'HEAD',
        ]);
    }

    /**
     * The regression this design guards against: a tenant-stamped request for a public
     * repo must still go through, anonymously, with no connection on file.
     */
    public function test_preflight_without_a_connection_succeeds_for_a_public_repo(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\tHEAD\n")]);
        $tenant = Tenant::factory()->create();

        app(RepositoryCloner::class)->preflight('https://github.com/acme/public', tenant: $tenant);

        Process::assertRan(fn (PendingProcess $process) => in_array('https://github.com/acme/public', $process->command, true));
    }

    /**
     * The security fix, exercised through the real preflight path: Tenant B's git
     * command never carries Tenant A's token.
     */
    public function test_preflight_does_not_leak_another_tenants_connection(): void
    {
        Process::fake(['*' => Process::result(exitCode: 128)]);
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create([
            'provider' => 'github',
            'access_token' => 'ghp_tenant_a_secret_token',
        ]);

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/app', tenant: $tenantB);
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException) {
            // expected: B has no connection, and the repo is private
        }

        Process::assertRan(fn (PendingProcess $process) => in_array('https://github.com/acme/app', $process->command, true));
        Process::assertDidntRun(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'ghp_tenant_a_secret_token'));
    }

    public function test_preflight_hands_git_the_connected_tenants_authenticated_url(): void
    {
        Process::fake(['*' => Process::result(output: "abc123\tHEAD\n")]);
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_connected_tenant_token',
        ]);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'git', 'ls-remote', '--exit-code', 'https://x-access-token:ghp_connected_tenant_token@github.com/acme/private', 'HEAD',
        ]);
    }

    public function test_clone_hands_git_the_connected_tenants_authenticated_url(): void
    {
        Process::fake(['*' => Process::result()]);
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'gitlab',
            'access_token' => 'glpat-connected-tenant-token',
        ]);
        $uuid = 'test-clone-auth-'.uniqid();
        $cloner = app(RepositoryCloner::class);

        try {
            $cloner->clone('https://gitlab.com/acme/private', $uuid, tenant: $tenant);
        } finally {
            $cloner->cleanup($uuid);
        }

        Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'clone'
            && str_contains(implode(' ', (array) $process->command), 'glpat-connected-tenant-token@gitlab.com/acme/private'));
    }

    /**
     * Failure messages are built from the raw URL (redactUrl($url)), never from the
     * resolved, credentialed one -- they end up in pipeline logs, the admin
     * notification and the customer email.
     */
    public function test_token_never_leaks_into_exception_messages(): void
    {
        Process::fake(['*' => Process::result(exitCode: 128)]);
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_secret_connected_token',
        ]);
        $cloner = app(RepositoryCloner::class);

        try {
            $cloner->preflight('https://github.com/acme/private', tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException from preflight()');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('ghp_secret_connected_token', $e->getMessage());
        }

        $uuid = 'test-clone-leak-'.uniqid();

        try {
            $cloner->clone('https://github.com/acme/private', $uuid, tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException from clone()');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('ghp_secret_connected_token', $e->getMessage());
        } finally {
            $cloner->cleanup($uuid);
        }

        // Proves the token really was in play -- otherwise the assertions above are vacuous.
        Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'ghp_secret_connected_token'));
    }

    public function test_remote_head_sha_returns_null_when_no_connection_exists_and_the_repo_is_private(): void
    {
        Process::fake(['*' => Process::result(exitCode: 128)]);
        $tenant = Tenant::factory()->create();

        $sha = app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/private', tenant: $tenant);

        $this->assertNull($sha);
    }

    public function test_remote_head_sha_still_resolves_a_public_local_repo_with_no_tenant(): void
    {
        $sha = app(RepositoryCloner::class)->remoteHeadSha('file://'.$this->fixtureRepo);

        $this->assertNotNull($sha);
    }

    /**
     * A real ProcessTimedOutException, built exactly the way PendingProcess::run() builds
     * one: its message is the full command line, credentialed URL and all.
     */
    private function fakeProcessTimeout(): void
    {
        Process::fake(function (PendingProcess $process) {
            $symfonyProcess = new SymfonyProcess((array) $process->command);

            throw new ProcessTimedOutException(
                new SymfonyProcessTimedOutException($symfonyProcess, SymfonyProcessTimedOutException::TYPE_GENERAL),
                new ProcessResult($symfonyProcess),
            );
        });
    }

    private function tenantWithConnectedToken(string $token): Tenant
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'access_token' => $token]);

        return $tenant;
    }

    public function test_the_faked_timeout_message_really_embeds_the_command_line(): void
    {
        $this->fakeProcessTimeout();

        try {
            Process::run(['git', 'ls-remote', 'https://x-access-token:ghp_probe@github.com/acme/app']);
            $this->fail('Expected ProcessTimedOutException');
        } catch (ProcessTimedOutException $e) {
            // Guards the tests below against passing vacuously.
            $this->assertStringContainsString('ghp_probe', $e->getMessage());
        }
    }

    public function test_a_preflight_timeout_never_leaks_the_token(): void
    {
        $this->fakeProcessTimeout();
        $tenant = $this->tenantWithConnectedToken('ghp_timeout_secret_token');

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertSame('Repository could not be reached: https://github.com/acme/private', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_a_clone_timeout_never_leaks_the_token_and_cleans_up(): void
    {
        $this->fakeProcessTimeout();
        $tenant = $this->tenantWithConnectedToken('ghp_timeout_secret_token');
        $uuid = 'test-clone-timeout-'.uniqid();
        $cloner = app(RepositoryCloner::class);

        try {
            $cloner->clone('https://github.com/acme/private', $uuid, tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertStringNotContainsString('ghp_timeout_secret_token', $e->getMessage());
            $this->assertStringContainsString('https://github.com/acme/private', $e->getMessage());
            $this->assertNull($e->getPrevious());
        } finally {
            $cloner->cleanup($uuid);
        }

        $this->assertDirectoryDoesNotExist(rtrim(config('audit.workdir'), '/').'/'.$uuid);
    }

    public function test_a_remote_head_sha_timeout_returns_null_instead_of_throwing(): void
    {
        $this->fakeProcessTimeout();
        $tenant = $this->tenantWithConnectedToken('ghp_timeout_secret_token');

        $this->assertNull(app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/private', tenant: $tenant));
    }

    /**
     * A corrupted/rotated APP_KEY makes decrypting the stored token throw; that must take
     * the same not-analyzable path, never escape as a raw exception.
     */
    public function test_an_undecryptable_token_is_reported_as_not_analyzable(): void
    {
        Process::fake();
        $tenant = Tenant::factory()->create();
        $connection = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);
        DB::table('tenant_git_connections')->where('id', $connection->id)->update(['access_token' => 'not-a-valid-ciphertext']);

        try {
            app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);
            $this->fail('Expected AuditNotAnalyzableException');
        } catch (AuditNotAnalyzableException $e) {
            $this->assertSame('Repository could not be reached: https://github.com/acme/private', $e->getMessage());
        }

        $this->assertNull(app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/private', tenant: $tenant));
        Process::assertNothingRan();
    }
}
