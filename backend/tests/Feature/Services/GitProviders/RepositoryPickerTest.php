<?php

namespace Tests\Feature\Services\GitProviders;

use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use App\Services\GitProviders\RepositoryPicker;
use App\Services\GitProviders\RepositoryPickerState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\FeatureTest;

class RepositoryPickerTest extends FeatureTest
{
    private function member(bool $mayManage = true): array
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, $mayManage ? [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS] : []);

        return [$tenant, $user];
    }

    private function connect(Tenant $tenant, string $provider = 'github', array $attributes = []): TenantGitConnection
    {
        return TenantGitConnection::factory()->create(['tenant_id' => $tenant->id, 'provider' => $provider] + $attributes);
    }

    private function fakeGithub(array $names): void
    {
        Http::fake(['api.github.com/user/repos*' => Http::response(array_map(fn ($n) => [
            'full_name' => $n,
            'html_url' => "https://github.com/{$n}",
            'private' => true,
            'default_branch' => 'main',
            'pushed_at' => '2026-09-01T10:00:00Z',
        ], $names))]);
    }

    public function test_providers_are_the_connected_ones_in_fixed_order(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'bitbucket');
        $this->connect($tenant, 'github');
        $otherTenant = Tenant::factory()->create();
        $this->connect($otherTenant, 'gitlab');

        $this->assertSame(['github', 'bitbucket'], app(RepositoryPicker::class)->providersFor($tenant, $user));
    }

    public function test_a_member_without_the_settings_permission_gets_no_providers_and_cannot_list(): void
    {
        [$tenant, $user] = $this->member(mayManage: false);
        $this->connect($tenant);
        Http::fake();

        $this->assertSame([], app(RepositoryPicker::class)->providersFor($tenant, $user));

        try {
            app(RepositoryPicker::class)->list($tenant, $user, 'github', null, 1);
            $this->fail('expected an AuthorizationException');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_user_outside_the_workspace_cannot_list(): void
    {
        [$tenant] = $this->member();
        $this->connect($tenant);
        $stranger = User::factory()->create();
        Http::fake();

        $this->expectException(AuthorizationException::class);

        app(RepositoryPicker::class)->list($tenant, $stranger, 'github', null, 1);
    }

    public function test_lists_with_the_current_workspaces_connection_only(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'github', ['access_token' => 'token-mine']);
        $other = Tenant::factory()->create();
        $this->connect($other, 'github', ['access_token' => 'token-other']);
        $this->fakeGithub(['acme/api']);

        $result = app(RepositoryPicker::class)->list($tenant, $user, 'github', null, 1);

        $this->assertSame(RepositoryPickerState::Ok, $result->state);
        $this->assertSame('acme/api', $result->page->items[0]->fullName);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-mine'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-other'));
    }

    public function test_no_connection_and_an_unknown_provider_are_no_connection(): void
    {
        [$tenant, $user] = $this->member();
        Http::fake();

        $picker = app(RepositoryPicker::class);

        $this->assertSame(RepositoryPickerState::NoConnection, $picker->list($tenant, $user, 'gitlab', null, 1)->state);
        $this->assertSame(RepositoryPickerState::NoConnection, $picker->list($tenant, $user, 'sourcehut', null, 1)->state);
        Http::assertNothingSent();
    }

    public function test_a_rejected_token_is_reconnect_and_an_outage_is_unavailable(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $picker = app(RepositoryPicker::class);

        Http::fake(['api.github.com/user/repos*' => Http::response([], 401)]);
        $this->assertSame(RepositoryPickerState::Reconnect, $picker->list($tenant, $user, 'github', null, 1)->state);

        Http::swap(new HttpFactory);
        Http::fake(['api.github.com/user/repos*' => Http::response([], 503)]);
        $this->assertSame(RepositoryPickerState::Unavailable, $picker->list($tenant, $user, 'github', null, 1)->state);
    }

    public function test_a_transient_token_refresh_failure_is_unavailable(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'gitlab', ['expires_at' => now()->subMinute(), 'refresh_token' => 'r']);
        Http::fake(['gitlab.com/oauth/token' => Http::response([], 503)]);

        $this->assertSame(RepositoryPickerState::Unavailable, app(RepositoryPicker::class)->list($tenant, $user, 'gitlab', null, 1)->state);
    }

    public function test_search_is_trimmed_and_length_capped(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'gitlab');
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([])]);

        app(RepositoryPicker::class)->list($tenant, $user, 'gitlab', '  '.str_repeat('a', 500).'  ', 1);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['search'] === str_repeat('a', 100);
        });
    }

    public function test_listing_is_rate_limited_per_user(): void
    {
        config(['audit.repo_picker.rate_limit_per_minute' => 2]);
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $this->fakeGithub(['acme/api']);
        RateLimiter::clear("repo-picker:{$user->id}");
        $picker = app(RepositoryPicker::class);

        $picker->list($tenant, $user, 'github', null, 1);
        $picker->list($tenant, $user, 'github', null, 1);
        $third = $picker->list($tenant, $user, 'github', null, 1);

        $this->assertSame(RepositoryPickerState::Throttled, $third->state);
        RateLimiter::clear("repo-picker:{$user->id}");
    }

    public function test_is_listed_only_for_urls_in_the_accounts_listing(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $this->fakeGithub(['acme/api']);
        $picker = app(RepositoryPicker::class);

        $this->assertTrue($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/api'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/secret'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://gitlab.com/acme/api'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/api/'));
    }

    public function test_a_refresh_rejected_with_invalid_grant_is_reconnect_not_no_connection(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'gitlab', ['expires_at' => now()->subMinute(), 'refresh_token' => 'revoked']);
        Http::fake(['gitlab.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame(RepositoryPickerState::Reconnect, app(RepositoryPicker::class)->list($tenant, $user, 'gitlab', null, 1)->state);
        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id, 'provider' => 'gitlab']);
    }
}
