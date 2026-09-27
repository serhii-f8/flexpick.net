<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitRepoAccessResolver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string, string, string, string}> */
    public static function refreshableProviders(): array
    {
        return [
            'gitlab' => ['gitlab', 'https://gitlab.com/acme/app', 'gitlab.com/oauth/token', 'https://oauth2:'],
            'bitbucket' => ['bitbucket', 'https://bitbucket.org/acme/app', 'bitbucket.org/site/oauth2/access_token', 'https://x-token-auth:'],
        ];
    }

    #[DataProvider('refreshableProviders')]
    public function test_an_expired_token_is_refreshed_and_persisted_before_use(string $provider, string $repoUrl, string $tokenEndpoint, string $clonePrefix): void
    {
        $this->freezeSecond();
        $tenant = Tenant::factory()->create();
        $connection = TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider,
            'access_token' => 'expired-access',
            'refresh_token' => 'valid-refresh',
            'expires_at' => now()->subMinute(),
        ]);
        Http::fake([$tokenEndpoint => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200])]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl($repoUrl, $tenant);

        $this->assertSame($clonePrefix.'new-access@'.substr($repoUrl, strlen('https://')), $url);
        $connection->refresh();
        $this->assertSame('new-access', $connection->access_token);
        $this->assertSame('new-refresh', $connection->refresh_token);
        $this->assertTrue($connection->expires_at->equalTo(now()->addSeconds(7200)));
        Http::assertSentCount(1);
    }

    #[DataProvider('refreshableProviders')]
    public function test_a_refresh_that_omits_a_new_refresh_token_keeps_the_old_one(string $provider, string $repoUrl, string $tokenEndpoint): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider, 'refresh_token' => 'kept-refresh', 'expires_at' => now()->subMinute(),
        ]);
        Http::fake([$tokenEndpoint => Http::response(['access_token' => 'new-access', 'expires_in' => 7200])]);

        $found = app(GitRepoAccessResolver::class)->connectionFor($repoUrl, $tenant);

        $this->assertSame('new-access', $found?->access_token);
        $this->assertSame('kept-refresh', $found?->refresh_token);
    }

    #[DataProvider('refreshableProviders')]
    public function test_a_rejected_refresh_deletes_the_connection_as_if_it_never_existed(string $provider, string $repoUrl, string $tokenEndpoint): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider,
            'access_token' => 'expired-access-must-not-leak',
            'refresh_token' => 'revoked-refresh',
            'expires_at' => now()->subMinute(),
        ]);
        Http::fake([$tokenEndpoint => Http::response(['error' => 'invalid_grant'], 400)]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl($repoUrl, $tenant);

        $this->assertSame($repoUrl, $url);
        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => $provider]);
        $this->assertNull(app(GitRepoAccessResolver::class)->connectionFor($repoUrl, $tenant));
    }

    #[DataProvider('refreshableProviders')]
    public function test_a_401_from_the_token_endpoint_also_deletes_the_connection(string $provider, string $repoUrl, string $tokenEndpoint): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider, 'refresh_token' => 'r', 'expires_at' => now()->subMinute(),
        ]);
        Http::fake([$tokenEndpoint => Http::response(['error' => 'invalid_client'], 401)]);

        $this->assertNull(app(GitRepoAccessResolver::class)->connectionFor($repoUrl, $tenant));
        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => $provider]);
    }

    #[DataProvider('refreshableProviders')]
    public function test_a_token_that_has_not_expired_is_used_without_a_refresh(string $provider, string $repoUrl, string $tokenEndpoint, string $clonePrefix): void
    {
        Http::fake();
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider,
            'access_token' => 'still-valid',
            'refresh_token' => 'valid-refresh',
            'expires_at' => now()->addHour(),
        ]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl($repoUrl, $tenant);

        $this->assertSame($clonePrefix.'still-valid@'.substr($repoUrl, strlen('https://')), $url);
        Http::assertNothingSent();
    }

    #[DataProvider('refreshableProviders')]
    public function test_a_provider_outage_during_refresh_keeps_the_connection_but_withholds_the_expired_token(string $provider, string $repoUrl, string $tokenEndpoint): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => $provider,
            'access_token' => 'expired-access-must-not-leak',
            'refresh_token' => 'valid-refresh',
            'expires_at' => now()->subMinute(),
        ]);
        Http::fake([$tokenEndpoint => Http::response(null, 503)]);

        $url = app(GitRepoAccessResolver::class)->resolveCloneUrl($repoUrl, $tenant);

        $this->assertSame($repoUrl, $url);
        $this->assertDatabaseHas('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => $provider]);
    }

    public function test_an_expired_github_connection_cannot_be_refreshed_and_is_dropped(): void
    {
        Http::fake();
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github', 'access_token' => 'expired-gh', 'expires_at' => now()->subMinute(),
        ]);

        $this->assertNull(app(GitRepoAccessResolver::class)->connectionFor('https://github.com/acme/app', $tenant));
        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'github']);
        Http::assertNothingSent();
    }
}
