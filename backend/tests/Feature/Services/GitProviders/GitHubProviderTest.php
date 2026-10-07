<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitHubProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitHubProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame('github', $provider->name());
        $this->assertSame('GitHub', $provider->label());
        $this->assertSame(['repo'], $provider->authorizationScopes());
    }

    public function test_credentials_are_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);

        $this->assertSame(
            ['username' => 'x-access-token', 'password' => 'ghp_tenant_token'],
            (new GitHubProvider)->cloneCredentials($connection),
        );
    }

    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_tenant_token'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['api.github.com/*' => Http::response(null, 404)]);

        $this->assertSame([], (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/private'));
    }

    public function test_caches_branches_per_connection(): void
    {
        $connectionA = TenantGitConnection::factory()->make(['id' => 1, 'access_token' => 'token-a']);
        $connectionB = TenantGitConnection::factory()->make(['id' => 2, 'access_token' => 'token-b']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $provider = new GitHubProvider;
        $provider->listBranches($connectionA, 'https://github.com/acme/app');
        $provider->listBranches($connectionA, 'https://github.com/acme/app');
        $provider->listBranches($connectionB, 'https://github.com/acme/app');

        Http::assertSentCount(2);
    }

    public function test_returns_branches_for_repo_with_dotted_name_without_git_suffix(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);
        Http::fake(['api.github.com/repos/acme/vue.js/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/vue.js');

        $this->assertSame(['main', 'develop'], $branches);
    }

    public function test_returns_branches_for_repo_with_dotted_name_and_git_suffix(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);
        Http::fake(['api.github.com/repos/acme/socket.io/branches*' => Http::response([
            ['name' => 'master'], ['name' => 'develop'],
        ])]);

        $branches = (new GitHubProvider)->listBranches($connection, 'https://github.com/acme/socket.io.git');

        $this->assertSame(['master', 'develop'], $branches);
    }

    /**
     * GitHub OAuth App tokens don't expire and OAuth Apps have no refresh_token grant:
     * refresh is never possible, and null tells the resolver to drop the connection.
     */
    public function test_refresh_token_is_never_possible(): void
    {
        Http::fake();
        $connection = TenantGitConnection::factory()->make(['refresh_token' => 'anything', 'expires_at' => now()->subHour()]);

        $this->assertNull((new GitHubProvider)->refreshToken($connection));
        Http::assertNothingSent();
    }

    private function githubRepo(string $fullName, bool $private = false, ?string $branch = 'main'): array
    {
        return [
            'full_name' => $fullName,
            'html_url' => "https://github.com/{$fullName}",
            'private' => $private,
            'default_branch' => $branch,
            'pushed_at' => '2026-09-01T10:00:00Z',
        ];
    }

    public function test_lists_repositories_newest_push_first_with_the_connections_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9101, 'access_token' => 'gho_list_token']);
        Http::fake(['api.github.com/user/repos*' => Http::response([
            $this->githubRepo('acme/api', true),
            $this->githubRepo('acme/web', false, null),
        ])]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/api', 'acme/web'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://github.com/acme/api', $page->items[0]->url);
        $this->assertTrue($page->items[0]->private);
        $this->assertNull($page->items[1]->defaultBranch);
        $this->assertSame('2026-09-01T10:00:00Z', $page->items[0]->updatedAt);
        $this->assertFalse($page->hasMore);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer gho_list_token')
            && str_contains($request->url(), 'sort=pushed')
            && str_contains($request->url(), 'per_page=100')
            && str_contains($request->url(), 'affiliation=owner%2Ccollaborator%2Corganization_member'));
    }

    public function test_follows_the_next_link_up_to_the_page_cap(): void
    {
        config(['audit.repo_picker.max_pages' => 2]);
        $connection = TenantGitConnection::factory()->make(['id' => 9102]);
        Http::fake([
            'api.github.com/user/repos?page=2*' => Http::response([$this->githubRepo('acme/two')], 200, ['Link' => '<https://api.github.com/user/repos?page=3>; rel="next"']),
            'api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/one')], 200, ['Link' => '<https://api.github.com/user/repos?page=2>; rel="next"']),
        ]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/one', 'acme/two'], array_map(fn ($e) => $e->fullName, $page->items));
        Http::assertSentCount(2); // page 3 exists but the cap stops the walk
    }

    public function test_never_sends_the_token_to_a_next_link_on_another_host(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9108, 'access_token' => 'gho_secret']);
        Http::fake([
            'api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/one')], 200, ['Link' => '<https://evil.example.com/steal?page=2>; rel="next"']),
            'evil.example.com/*' => Http::response([$this->githubRepo('evil/repo')]),
        ]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/one'], array_map(fn ($e) => $e->fullName, $page->items));
        Http::assertSentCount(1);
    }

    public function test_a_malformed_cached_listing_is_a_cache_miss(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9109]);
        Cache::put('github_repos:9109', [['nonsense' => 1], 'junk'], 300);
        Http::fake(['api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/api')])]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/api'], array_map(fn ($e) => $e->fullName, $page->items));
    }

    public function test_search_filters_locally_and_paginates_twenty_at_a_time(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9103]);
        $repos = array_map(fn ($i) => $this->githubRepo("acme/svc-{$i}"), range(1, 25));
        $repos[] = $this->githubRepo('acme/website');
        Http::fake(['api.github.com/user/repos*' => Http::response($repos)]);

        $provider = new GitHubProvider;
        $first = $provider->listRepositories($connection, 'SVC', 1);
        $second = $provider->listRepositories($connection, 'svc', 2);
        $none = $provider->listRepositories($connection, 'nothing-like-this', 1);

        $this->assertCount(20, $first->items);
        $this->assertTrue($first->hasMore);
        $this->assertCount(5, $second->items);
        $this->assertSame([], $none->items);
    }

    public function test_an_empty_listing_is_not_cached_so_a_new_repository_shows_up_at_once(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9106]);
        Http::fake(['api.github.com/user/repos*' => Http::response([])]);

        $provider = new GitHubProvider;
        $this->assertSame([], $provider->listRepositories($connection, null, 1)->items);
        $provider->listRepositories($connection, null, 1);

        Http::assertSentCount(2);
    }

    public function test_the_listing_is_cached_per_connection_and_not_shared_between_connections(): void
    {
        $a = TenantGitConnection::factory()->make(['id' => 9104, 'access_token' => 'token-a']);
        $b = TenantGitConnection::factory()->make(['id' => 9105, 'access_token' => 'token-b']);
        Http::fake(['api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/api')])]);

        $provider = new GitHubProvider;
        $provider->listRepositories($a, null, 1);
        $provider->listRepositories($a, 'api', 1); // same cached listing, different filter
        Http::assertSentCount(1);

        $provider->listRepositories($b, null, 1);
        Http::assertSentCount(2);
    }

    public function test_a_401_or_plain_403_is_a_rejection_and_is_not_cached(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9106]);
        Http::fake(['api.github.com/user/repos*' => Http::response(['message' => 'Bad credentials'], 401)]);

        try {
            (new GitHubProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a rejection');
        } catch (GitRepositoryListingRejectedException $e) {
            $this->assertStringNotContainsString($connection->access_token, $e->getMessage());
        }

        Http::swap(new HttpFactory); // earlier stubs win over later ones, so start clean
        Http::fake(['api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/api')])]);
        $this->assertCount(1, (new GitHubProvider)->listRepositories($connection, null, 1)->items);
    }

    public function test_rate_limits_5xx_and_network_failures_are_transient(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9107]);

        foreach ([
            Http::response([], 403, ['X-RateLimit-Remaining' => '0']),
            Http::response([], 502),
        ] as $response) {
            Http::swap(new HttpFactory);
            Http::fake(['api.github.com/user/repos*' => $response]);

            try {
                (new GitHubProvider)->listRepositories($connection, null, 1);
                $this->fail('expected a transient failure');
            } catch (GitAccessTemporarilyUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::swap(new HttpFactory);
        Http::fake(['api.github.com/user/repos*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new GitHubProvider)->listRepositories($connection, null, 1);
    }
}
