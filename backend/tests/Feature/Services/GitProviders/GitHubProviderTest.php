<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitHubProvider;
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

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'ghp_tenant_token']);

        $url = (new GitHubProvider)->cloneUrl($connection, 'https://github.com/acme/app');

        $this->assertSame('https://x-access-token:ghp_tenant_token@github.com/acme/app', $url);
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
}
