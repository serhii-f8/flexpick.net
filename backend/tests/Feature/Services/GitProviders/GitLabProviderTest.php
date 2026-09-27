<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\GitProviders\GitLabProvider;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitLabProviderTest extends FeatureTest
{
    public function test_identity(): void
    {
        $provider = new GitLabProvider;

        $this->assertSame('gitlab', $provider->name());
        $this->assertSame('GitLab', $provider->label());
        $this->assertSame(['read_repository'], $provider->authorizationScopes());
    }

    public function test_clone_url_embeds_the_connections_own_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['access_token' => 'glpat_tenant_token']);

        $url = (new GitLabProvider)->cloneUrl($connection, 'https://gitlab.com/acme/app');

        $this->assertSame('https://oauth2:glpat_tenant_token@gitlab.com/acme/app', $url);
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
}
