<?php

namespace Tests\Feature\Services\AuditReport;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
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

    public function test_preflight_throws_when_a_connected_provider_host_has_no_tenant_connection(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(AuditNotAnalyzableException::class);
        $this->expectExceptionMessage('Connect your GitHub account to audit this repository.');

        app(RepositoryCloner::class)->preflight('https://github.com/acme/private', tenant: $tenant);
    }

    /**
     * The security fix, exercised through the real preflight path: Tenant B
     * cannot reach a repo using Tenant A's connection.
     */
    public function test_preflight_does_not_leak_another_tenants_connection(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create(['provider' => 'github']);

        $this->expectException(AuditNotAnalyzableException::class);

        app(RepositoryCloner::class)->preflight('https://github.com/acme/app', tenant: $tenantB);
    }

    public function test_remote_head_sha_returns_null_instead_of_throwing_when_no_connection_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $sha = app(RepositoryCloner::class)->remoteHeadSha('https://github.com/acme/private', tenant: $tenant);

        $this->assertNull($sha);
    }

    public function test_remote_head_sha_still_resolves_a_public_local_repo_with_no_tenant(): void
    {
        $sha = app(RepositoryCloner::class)->remoteHeadSha('file://'.$this->fixtureRepo);

        $this->assertNotNull($sha);
    }
}
