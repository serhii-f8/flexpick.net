<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitTokenRefreshUnavailableException;
use App\Models\TenantGitConnection;
use App\Services\GitProviders\BitbucketProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class BitbucketProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new BitbucketProvider;

        $this->assertSame('bitbucket', $provider->name());
        $this->assertSame('Bitbucket', $provider->label());
        $this->assertSame(['repository'], $provider->authorizationScopes());
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
}
