<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Exceptions\GitTokenRefreshUnavailableException;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\BitbucketProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class BitbucketProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new BitbucketProvider;

        $this->assertSame('bitbucket', $provider->name());
        $this->assertSame('Bitbucket', $provider->label());
        $this->assertSame(['repository', 'account'], $provider->authorizationScopes());
    }

    public function test_credentials_are_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);

        $this->assertSame(
            ['username' => 'x-token-auth', 'password' => 'bb_tenant_token'],
            (new BitbucketProvider)->cloneCredentials($connection),
        );
    }

    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/app/refs/branches*' => Http::response([
            'values' => [['name' => 'main'], ['name' => 'develop']],
        ])]);

        $branches = (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bb_tenant_token'));
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'pagelen=100'));
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        $connection = TenantGitConnection::factory()->make();
        Http::fake(['api.bitbucket.org/*' => Http::response(null, 404)]);

        $this->assertSame([], (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/private'));
    }

    public function test_returns_branches_for_repo_with_dotted_name_without_git_suffix(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/vue.js/refs/branches*' => Http::response([
            'values' => [['name' => 'main']],
        ])]);

        $branches = (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/vue.js');

        $this->assertSame(['main'], $branches);
    }

    public function test_returns_branches_for_repo_with_dotted_name_and_git_suffix(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/vue.js/refs/branches*' => Http::response([
            'values' => [['name' => 'main']],
        ])]);

        $branches = (new BitbucketProvider)->listBranches($connection, 'https://bitbucket.org/acme/vue.js.git');

        $this->assertSame(['main'], $branches);
    }

    public function test_caches_branches_per_connection(): void
    {
        $connectionA = TenantGitConnection::factory()->make(['id' => 1, 'access_token' => 'token-a']);
        $connectionB = TenantGitConnection::factory()->make(['id' => 2, 'access_token' => 'token-b']);
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/app/refs/branches*' => Http::response([
            'values' => [['name' => 'main']],
        ])]);

        $provider = new BitbucketProvider;
        $provider->listBranches($connectionA, 'https://bitbucket.org/acme/app');
        $provider->listBranches($connectionA, 'https://bitbucket.org/acme/app');
        $provider->listBranches($connectionB, 'https://bitbucket.org/acme/app');

        Http::assertSentCount(2);
    }

    public function test_refresh_token_posts_the_refresh_grant_with_basic_auth_to_the_bitbucket_token_endpoint(): void
    {
        config()->set('services.bitbucket', ['client_id' => 'bb-key', 'client_secret' => 'bb-secret', 'redirect' => '/auth/bitbucket/callback']);
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => 'bb-refresh-old']);
        Http::fake(['bitbucket.org/site/oauth2/access_token' => Http::response([
            'access_token' => 'bb-access-new', 'scopes' => 'repository', 'expires_in' => 7200, 'refresh_token' => 'bb-refresh-new', 'token_type' => 'bearer',
        ])]);

        $result = (new BitbucketProvider)->refreshToken($connection);

        $this->assertSame(['access_token' => 'bb-access-new', 'refresh_token' => 'bb-refresh-new', 'expires_in' => 7200], $result);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://bitbucket.org/site/oauth2/access_token'
            && $request->isForm()
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('bb-key:bb-secret'))
            && $request->data() === ['grant_type' => 'refresh_token', 'refresh_token' => 'bb-refresh-old']);
    }

    public function test_refresh_token_returns_null_when_bitbucket_rejects_the_grant(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => 'revoked']);
        Http::fake(['bitbucket.org/site/oauth2/access_token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNull((new BitbucketProvider)->refreshToken($connection));
    }

    public function test_refresh_token_returns_null_without_a_request_when_no_refresh_token_is_on_file(): void
    {
        Http::fake();
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => null]);

        $this->assertNull((new BitbucketProvider)->refreshToken($connection));
        Http::assertNothingSent();
    }

    public function test_refresh_token_reports_an_unreachable_endpoint_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => 'bb-refresh']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new BitbucketProvider)->refreshToken($connection);
    }

    /**
     * A 429 (or any other 4xx that isn't the specific "this refresh_token is dead"
     * rejection) must not delete the tenant's connection -- only RFC 6749 §5.2's
     * `invalid_grant` means that.
     */
    public function test_refresh_token_reports_a_rate_limit_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => 'bb-refresh']);
        Http::fake(['bitbucket.org/site/oauth2/access_token' => Http::response(null, 429)]);

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new BitbucketProvider)->refreshToken($connection);
    }

    /**
     * invalid_client means OUR consumer key/secret is wrong, not that the
     * tenant's refresh_token is dead -- deleting the connection would be
     * wrong and would mass-disconnect every tenant on a config mistake.
     */
    public function test_refresh_token_reports_invalid_client_as_transient_not_as_a_rejection(): void
    {
        $connection = TenantGitConnection::factory()->make(['provider' => 'bitbucket', 'refresh_token' => 'bb-refresh']);
        Http::fake(['bitbucket.org/site/oauth2/access_token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(GitTokenRefreshUnavailableException::class);

        (new BitbucketProvider)->refreshToken($connection);
    }

    private function bitbucketRepo(string $fullName, bool $private = true, ?string $branch = 'main', string $updated = '2026-09-03T09:00:00.000000+00:00'): array
    {
        return [
            'full_name' => $fullName,
            'is_private' => $private,
            'mainbranch' => $branch === null ? null : ['name' => $branch],
            'updated_on' => $updated,
        ];
    }

    private function fakeBitbucket(array $workspaces, array $reposBySlug): void
    {
        Http::fake(array_merge(
            ['api.bitbucket.org/2.0/workspaces*' => Http::response(['values' => array_map(fn ($s) => ['slug' => $s], $workspaces)])],
            collect($reposBySlug)->mapWithKeys(fn ($repos, $slug) => ["api.bitbucket.org/2.0/repositories/{$slug}*" => Http::response(['values' => $repos])])->all(),
        ));
    }

    public function test_lists_repositories_across_the_members_workspaces_newest_first(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9301, 'access_token' => 'bb_list_token']);
        $this->fakeBitbucket(['acme', 'side'], [
            'acme' => [$this->bitbucketRepo('acme/api', true, 'main', '2026-09-01T00:00:00.000000+00:00')],
            'side' => [$this->bitbucketRepo('side/blog', false, null, '2026-09-05T00:00:00.000000+00:00')],
        ]);

        $page = (new BitbucketProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['side/blog', 'acme/api'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://bitbucket.org/side/blog', $page->items[0]->url);
        $this->assertFalse($page->items[0]->private);
        $this->assertNull($page->items[0]->defaultBranch);
        $this->assertSame('main', $page->items[1]->defaultBranch);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bb_list_token')
            && str_contains($request->url(), 'role=member'));
    }

    public function test_search_filters_locally_and_the_listing_is_cached(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9302]);
        $this->fakeBitbucket(['acme'], ['acme' => [$this->bitbucketRepo('acme/api'), $this->bitbucketRepo('acme/web')]]);

        $provider = new BitbucketProvider;
        $hit = $provider->listRepositories($connection, 'WEB', 1);
        $all = $provider->listRepositories($connection, null, 1);

        $this->assertSame(['acme/web'], array_map(fn ($e) => $e->fullName, $hit->items));
        $this->assertCount(2, $all->items);
        Http::assertSentCount(2); // one workspaces call + one repositories call, then cached
    }

    public function test_a_malformed_cached_listing_is_a_miss(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9306]);
        Cache::put('bitbucket_repos:9306', ['garbage' => true], 60);
        $this->fakeBitbucket(['acme'], ['acme' => [$this->bitbucketRepo('acme/api')]]);

        $page = (new BitbucketProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/api'], array_map(fn ($e) => $e->fullName, $page->items));
    }

    public function test_a_member_with_no_workspaces_gets_an_empty_page(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9303]);
        $this->fakeBitbucket([], []);

        $this->assertSame([], (new BitbucketProvider)->listRepositories($connection, null, 1)->items);
    }

    public function test_a_403_on_the_workspace_list_means_the_account_scope_is_missing(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9304]);
        Http::fake(['api.bitbucket.org/2.0/workspaces*' => Http::response(['error' => ['message' => 'Your credentials lack one or more required privilege scopes.']], 403)]);

        $this->expectException(GitRepositoryListingRejectedException::class);

        (new BitbucketProvider)->listRepositories($connection, null, 1);
    }

    public function test_a_workspace_that_rejects_the_member_is_skipped_not_fatal(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9306]);
        Http::fake([
            'api.bitbucket.org/2.0/workspaces*' => Http::response(['values' => [['slug' => 'locked'], ['slug' => 'acme']]]),
            'api.bitbucket.org/2.0/repositories/locked*' => Http::response([], 403),
            'api.bitbucket.org/2.0/repositories/acme*' => Http::response(['values' => [$this->bitbucketRepo('acme/api')]]),
        ]);

        $items = (new BitbucketProvider)->listRepositories($connection, null, 1)->items;

        $this->assertSame(['acme/api'], array_map(fn ($e) => $e->fullName, $items));
    }

    public function test_a_transient_failure_on_one_workspace_still_fails_the_listing(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9307]);
        Http::fake([
            'api.bitbucket.org/2.0/workspaces*' => Http::response(['values' => [['slug' => 'acme']]]),
            'api.bitbucket.org/2.0/repositories/acme*' => Http::response([], 503),
        ]);

        $this->expectException(GitAccessTemporarilyUnavailableException::class);

        (new BitbucketProvider)->listRepositories($connection, null, 1);
    }

    public function test_malformed_slugs_and_repository_rows_are_skipped(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9308]);
        Http::fake([
            'api.bitbucket.org/2.0/workspaces*' => Http::response(['values' => [['slug' => ['x']], 'junk', ['slug' => 'acme']]]),
            'api.bitbucket.org/2.0/repositories/acme*' => Http::response(['values' => [['full_name' => ['bad']], 'junk', $this->bitbucketRepo('acme/api')]]),
        ]);

        $items = (new BitbucketProvider)->listRepositories($connection, null, 1)->items;

        $this->assertSame(['acme/api'], array_map(fn ($e) => $e->fullName, $items));
    }

    public function test_transient_failures_are_distinguished_from_rejections(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9305]);

        Http::fake(['api.bitbucket.org/2.0/workspaces*' => Http::response([], 500)]);
        try {
            (new BitbucketProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a transient failure');
        } catch (GitAccessTemporarilyUnavailableException) {
            $this->addToAssertionCount(1);
        }

        Http::swap(new HttpFactory);
        Http::fake(['api.bitbucket.org/2.0/workspaces*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new BitbucketProvider)->listRepositories($connection, null, 1);
    }
}
