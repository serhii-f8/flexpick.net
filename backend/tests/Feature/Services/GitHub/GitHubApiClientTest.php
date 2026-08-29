<?php

namespace Tests\Feature\Services\GitHub;

use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class GitHubApiClientTest extends FeatureTest
{
    public function test_returns_branch_names_for_a_valid_repo(): void
    {
        config(['audit.github_token' => 'ghp_test']);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([
            ['name' => 'main'], ['name' => 'develop'],
        ])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app');

        $this->assertSame(['main', 'develop'], $branches);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test'));
    }

    public function test_returns_branch_names_when_the_url_has_a_dot_git_suffix(): void
    {
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app.git');

        $this->assertSame(['main'], $branches);
    }

    public function test_returns_empty_array_for_an_inaccessible_repo(): void
    {
        Http::fake(['api.github.com/*' => Http::response(null, 404)]);

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://github.com/acme/private'));
    }

    public function test_returns_empty_array_for_a_non_github_url(): void
    {
        Http::fake();

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://gitlab.com/acme/app'));
        Http::assertNothingSent();
    }

    public function test_returns_empty_array_on_network_failure(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $this->assertSame([], app(GitHubApiClient::class)->listBranches('https://github.com/acme/app'));
    }

    /**
     * Every scheduled repo row on the audit reports page fires an x-init
     * branch lookup, so a user with several scheduled repos used to trigger
     * that many live calls to api.github.com before the page finished
     * rendering -- on every single page load. Branch lists rarely change, and
     * the rate-limit budget being spent is the shared PAT's, across all
     * tenants.
     */
    public function test_repeated_lookups_of_the_same_repo_hit_the_api_once(): void
    {
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $client = app(GitHubApiClient::class);

        $this->assertSame(['main'], $client->listBranches('https://github.com/acme/app'));
        $this->assertSame(['main'], $client->listBranches('https://github.com/acme/app'));
        $this->assertSame(['main'], $client->listBranches('https://github.com/acme/app.git'));

        Http::assertSentCount(1);
    }

    public function test_different_repos_are_cached_separately(): void
    {
        Http::fake([
            'api.github.com/repos/acme/one/branches*' => Http::response([['name' => 'one-main']]),
            'api.github.com/repos/acme/two/branches*' => Http::response([['name' => 'two-main']]),
        ]);

        $client = app(GitHubApiClient::class);

        $this->assertSame(['one-main'], $client->listBranches('https://github.com/acme/one'));
        $this->assertSame(['two-main'], $client->listBranches('https://github.com/acme/two'));

        Http::assertSentCount(2);
    }

    public function test_works_without_a_configured_token(): void
    {
        config(['audit.github_token' => null]);
        Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main']])]);

        $branches = app(GitHubApiClient::class)->listBranches('https://github.com/acme/app');

        $this->assertSame(['main'], $branches);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }
}
