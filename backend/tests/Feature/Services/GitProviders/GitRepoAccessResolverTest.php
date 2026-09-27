<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitRepoAccessResolver;
use Tests\Feature\FeatureTest;

class GitRepoAccessResolverTest extends FeatureTest
{
    public function test_returns_the_authenticated_clone_url_for_a_connected_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_tenant_token',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);

        $this->assertSame('https://x-access-token:ghp_tenant_token@github.com/acme/app', $url);
    }

    public function test_throws_when_the_tenant_has_no_connection_for_that_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(AuditNotAnalyzableException::class);
        $this->expectExceptionMessage('Connect your GitHub account to audit this repository.');

        app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);
    }

    /**
     * The core security fix: Tenant A's GitHub connection must never be used
     * to clone a repo on Tenant B's behalf, even though both reference the
     * same provider.
     */
    public function test_tenant_a_connection_is_never_used_for_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create([
            'provider' => 'github',
            'access_token' => 'tenant-a-token',
        ]);

        $this->expectException(AuditNotAnalyzableException::class);

        app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/private', $tenantB);
    }

    public function test_returns_the_url_unchanged_when_no_tenant_is_given(): void
    {
        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/public', null);

        $this->assertSame('https://github.com/acme/public', $url);
    }

    public function test_returns_the_url_unchanged_for_an_unrecognized_host(): void
    {
        $tenant = Tenant::factory()->create();

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://example.com/acme/app', $tenant);

        $this->assertSame('https://example.com/acme/app', $url);
    }

    public function test_connection_for_returns_the_matching_tenant_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $connection = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'gitlab']);

        $found = app(GitRepoAccessResolver::class)->connectionFor('https://gitlab.com/acme/app', $tenant);

        $this->assertSame($connection->id, $found?->id);
    }

    public function test_connection_for_returns_null_when_none_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull(app(GitRepoAccessResolver::class)->connectionFor('https://gitlab.com/acme/app', $tenant));
    }
}
