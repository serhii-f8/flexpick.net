<?php

namespace Tests\Feature\Services\GitProviders;

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

    /**
     * A miss is not a denial: the URL passes through unauthenticated, so a public repo
     * still clones anonymously and a private one fails git's own reachability check.
     */
    public function test_returns_the_url_unchanged_when_the_tenant_has_no_connection_for_that_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);

        $this->assertSame('https://github.com/acme/app', $url);
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

        $urlForB = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/private', $tenantB);

        $this->assertStringNotContainsString('tenant-a-token', $urlForB);
        $this->assertSame('https://github.com/acme/private', $urlForB);
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

    /**
     * The regression: parse_url() routes http://github.com/... to GitHubProvider by host
     * alone, but GitHubProvider::cloneUrl() blindly strips a literal 8-char "https://"
     * prefix. Against a 7-char "http://" URL that strip eats one extra character of the
     * host ("http://g" instead of "http://"), corrupting "github.com" into "ithub.com" and
     * sending the tenant's real token to the wrong domain. Must be passed through unchanged.
     */
    public function test_a_non_https_scheme_is_never_given_a_connections_token(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'tenant-token-must-not-leak',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('http://github.com/acme/app', $tenant);

        $this->assertSame('http://github.com/acme/app', $url);
        $this->assertStringNotContainsString('tenant-token-must-not-leak', $url);
    }

    public function test_each_tenant_gets_their_own_token_never_the_others(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenantA)->create(['provider' => 'github', 'access_token' => 'tenant-a-token']);
        TenantGitConnection::factory()->for($tenantB)->create(['provider' => 'github', 'access_token' => 'tenant-b-token']);

        $urlForB = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenantB);

        $this->assertStringContainsString('tenant-b-token', $urlForB);
        $this->assertStringNotContainsString('tenant-a-token', $urlForB);
    }

    public function test_returns_the_url_unchanged_when_tenant_has_a_connection_for_a_different_provider(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'gitlab',
            'access_token' => 'glpat-gitlab-only-token',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com/acme/app', $tenant);

        $this->assertSame('https://github.com/acme/app', $url);
        $this->assertStringNotContainsString('glpat-gitlab-only-token', $url);
    }

    public function test_a_url_with_embedded_userinfo_is_never_given_a_connections_token(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'tenant-token-must-not-leak',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://evil:pw@github.com/acme/app', $tenant);

        $this->assertSame('https://evil:pw@github.com/acme/app', $url);
        $this->assertStringNotContainsString('tenant-token-must-not-leak', $url);
    }

    public function test_a_url_with_an_explicit_port_is_never_given_a_connections_token(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'tenant-token-must-not-leak',
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl('https://github.com:443/acme/app', $tenant);

        $this->assertSame('https://github.com:443/acme/app', $url);
        $this->assertStringNotContainsString('tenant-token-must-not-leak', $url);
    }
}
