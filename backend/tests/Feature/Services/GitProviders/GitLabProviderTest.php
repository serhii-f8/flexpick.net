<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Exceptions\GitTokenRefreshUnavailableException;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitLabProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitLabProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new GitLabProvider;

        $this->assertSame('gitlab', $provider->name());
        $this->assertSame('GitLab', $provider->label());
        $this->assertSame(['read_repository', 'read_api'], $provider->authorizationScopes());
    }

    public function test_credentials_are_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);

        $this->assertSame(
            ['username' => 'oauth2', 'password' => 'glpat_tenant_token'],
            (new GitLabProvider)->cloneCredentials($connection),
        );
    }

    public function test_returns_branch_names_for_a_nested_group_path(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);
        Http::fake(['gitlab.com/api/v4/projects/acme%2Fteam%2Fapp/repository/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = (new GitLabProvider)->listBranches($connection, 'https://gitlab.com/acme/team/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer glpat_tenant_token'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['gitlab.com/*' => Http::response(null, 404)]);

        $this->assertSame([], (new GitLabProvider)->listBranches($connection, 'https://gitlab.com/acme/private'));
    }

    public function test_returns_branches_for_repo_with_dotted_name(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);
        Http::fake(['gitlab.com/api/v4/projects/acme%2Fmy.project/repository/branches*' => Http::response([
            ['name' => 'main'],
        ])]);

        $branches = (new GitLabProvider)->listBranches($connection, 'https://gitlab.com/acme/my.project');

        $this->assertSame(['main'], $branches);
    }

    public function test_caches_branches_per_connection(): void
    {
        $connectionA = TenantGitConnection::factory()->make(['id' => 1, 'access_token' => 'token-a']);
        $connectionB = TenantGitConnection::factory()->make(['id' => 2, 'access_token' => 'token-b']);
        Http::fake(['gitlab.com/api/v4/projects/acme%2Fapp/repository/branches*' => Http::response([['name' => 'main']])]);

        $provider = new GitLabProvider;
        $provider->listBranches($connectionA, 'https://gitlab.com/acme/app');
        $provider->listBranches($connectionA, 'https://gitlab.com/acme/app');
        $provider->listBranches($connectionB, 'https://gitlab.com/acme/app');

        Http::assertSentCount(2);
    }

    public function test_refresh_token_posts_the_refresh_grant_to_the_gitlab_token_endpoint(): void
    {
        config()->set('services.gitlab', ['client_id' => 'gl-id', 'client_secret' => 'gl-secret', 'redirect' => 'https://app.test/auth/gitlab/callback']);
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'gl-refresh-old']);
        Http::fake(['gitlab.com/oauth/token' => Http::response([
            'access_token' => 'gl-access-new', 'token_type' => 'Bearer', 'expires_in' => 7200, 'refresh_token' => 'gl-refresh-new', 'created_at' => 1700000000,
        ])]);

        $result = (new GitLabProvider)->refreshToken($connection);

        $this->assertSame(['access_token' => 'gl-access-new', 'refresh_token' => 'gl-refresh-new', 'expires_in' => 7200], $result);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://gitlab.com/oauth/token'
            && $request->isForm()
            && $request->data() === [
                'client_id' => 'gl-id',
                'client_secret' => 'gl-secret',
                'refresh_token' => 'gl-refresh-old',
                'grant_type' => 'refresh_token',
                'redirect_uri' => 'https://app.test/auth/gitlab/callback',
            ]);
    }

    public function test_refresh_token_resolves_a_relative_redirect_like_socialite_does(): void
    {
        config()->set('services.gitlab.redirect', '/auth/gitlab/callback');
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'gl-refresh']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(['access_token' => 'x', 'expires_in' => 7200])]);

        (new GitLabProvider)->refreshToken($connection);

        Http::assertSent(fn (Request $request) => $request['redirect_uri'] === url('/auth/gitlab/callback'));
    }

    public function test_refresh_token_returns_null_when_gitlab_rejects_the_grant(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'revoked']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNull((new GitLabProvider)->refreshToken($connection));
    }

    public function test_refresh_token_returns_null_without_a_request_when_no_refresh_token_is_on_file(): void
    {
        Http::fake();
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => null]);

        $this->assertNull((new GitLabProvider)->refreshToken($connection));
        Http::assertNothingSent();
    }

    public function test_refresh_token_reports_a_provider_outage_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'gl-refresh']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(null, 503)]);

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new GitLabProvider)->refreshToken($connection);
    }

    /**
     * A 429 (or any other 4xx that isn't the specific "this refresh_token is dead"
     * rejection) must not delete the tenant's connection -- only RFC 6749 §5.2's
     * `invalid_grant` means that.
     */
    public function test_refresh_token_reports_a_rate_limit_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'gl-refresh']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(null, 429)]);

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new GitLabProvider)->refreshToken($connection);
    }

    /**
     * invalid_client means OUR client_id/secret is wrong, not that the
     * tenant's refresh_token is dead -- deleting the connection would be
     * wrong and would mass-disconnect every tenant on a config mistake.
     */
    public function test_refresh_token_reports_invalid_client_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'gitlab', 'refresh_token' => 'gl-refresh']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new GitLabProvider)->refreshToken($connection);
    }

    private function gitlabProject(string $path, string $visibility = 'private', ?string $branch = 'main'): array
    {
        return [
            'path_with_namespace' => $path,
            'web_url' => "https://gitlab.com/{$path}",
            'visibility' => $visibility,
            'default_branch' => $branch,
            'last_activity_at' => '2026-09-02T08:30:00.000Z',
        ];
    }

    public function test_lists_projects_by_last_activity_with_server_side_search_and_paging(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9201, 'access_token' => 'glpat_list']);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response(
            [$this->gitlabProject('acme/team/app'), $this->gitlabProject('acme/site', 'public', null)],
            200,
            ['X-Next-Page' => '3'],
        )]);

        $page = (new GitLabProvider)->listRepositories($connection, ' app ', 2);

        $this->assertSame(['acme/team/app', 'acme/site'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://gitlab.com/acme/team/app', $page->items[0]->url);
        $this->assertTrue($page->items[0]->private);
        $this->assertFalse($page->items[1]->private); // public visibility
        $this->assertNull($page->items[1]->defaultBranch);
        $this->assertTrue($page->hasMore);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer glpat_list')
            && str_contains($request->url(), 'membership=true')
            && str_contains($request->url(), 'archived=false')
            && str_contains($request->url(), 'order_by=last_activity_at')
            && str_contains($request->url(), 'per_page=20')
            && str_contains($request->url(), 'page=2')
            && str_contains($request->url(), 'search=app'));
    }

    public function test_an_empty_next_page_header_means_no_more_and_no_search_param_is_sent_when_blank(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9202]);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([$this->gitlabProject('acme/app')], 200, ['X-Next-Page' => ''])]);

        $page = (new GitLabProvider)->listRepositories($connection, '   ', 1);

        $this->assertFalse($page->hasMore);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'search='));
    }

    public function test_pages_are_cached_per_connection_search_and_page(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9203]);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([$this->gitlabProject('acme/app')])]);

        $provider = new GitLabProvider;
        $provider->listRepositories($connection, 'app', 1);
        $provider->listRepositories($connection, 'APP ', 1); // same normalised search
        Http::assertSentCount(1);

        $provider->listRepositories($connection, 'app', 2);
        Http::assertSentCount(2);
    }

    public function test_a_malformed_cached_page_is_a_miss(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9205]);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([$this->gitlabProject('acme/app')])]);

        $provider = new GitLabProvider;
        $provider->listRepositories($connection, null, 1);
        Http::assertSentCount(1);

        $key = "gitlab_repos:{$connection->id}:".md5('').':1';
        $this->assertTrue(Cache::has($key));
        Cache::put($key, ['items' => [['bogus' => 1]], 'has_more' => false], 60);

        $page = $provider->listRepositories($connection, null, 1);

        Http::assertSentCount(2);
        $this->assertSame('acme/app', $page->items[0]->fullName);
    }

    public function test_rejections_and_transient_failures_are_distinguished(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9204]);

        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([], 403)]);
        try {
            (new GitLabProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a rejection');
        } catch (GitRepositoryListingRejectedException) {
            $this->addToAssertionCount(1);
        }

        Http::swap(new HttpFactory);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([], 503)]);
        try {
            (new GitLabProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a transient failure');
        } catch (GitAccessTemporarilyUnavailableException) {
            $this->addToAssertionCount(1);
        }

        Http::swap(new HttpFactory);
        Http::fake(['gitlab.com/api/v4/projects*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new GitLabProvider)->listRepositories($connection, null, 1);
    }
}
