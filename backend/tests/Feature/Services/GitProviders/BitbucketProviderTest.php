<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\BitbucketProvider;
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

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'bb_tenant_token']);

        $url = (new BitbucketProvider)->cloneUrl($connection, 'https://bitbucket.org/acme/app');

        $this->assertSame('https://x-token-auth:bb_tenant_token@bitbucket.org/acme/app', $url);
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
}
