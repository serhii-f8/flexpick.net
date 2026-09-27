<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\Tenant;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\FeatureTest;

class GitConnectionOAuthTest extends FeatureTest
{
    public function test_redirect_requires_authentication(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/auth/github/redirect?intent=git_connection&tenant_id=1');

        $response->assertForbidden();
    }

    public function test_redirect_rejects_a_tenant_the_user_does_not_belong_to(): void
    {
        $this->withExceptionHandling();

        $user = User::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $response = $this->actingAs($user)->get("/auth/github/redirect?intent=git_connection&tenant_id={$otherTenant->id}");

        $response->assertForbidden();
    }

    public function test_callback_stores_the_connection_for_the_authorized_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => 'ghp_callback_token']);
        Socialite::shouldReceive('driver')
            ->with('github')
            ->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialiteUser);

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $response->assertRedirect(route('filament.dashboard.pages.git-connections', ['tenant' => $tenant]));
        $response->assertSessionMissing('git_connection_tenant_id');

        $this->assertDatabaseHas('tenant_git_connections', [
            'tenant_id' => $tenant->id,
            'provider' => 'github',
            'account_login' => 'octocat',
            'connected_by_user_id' => $user->id,
        ]);
    }

    public function test_callback_rejects_a_user_removed_from_the_tenant_after_redirect(): void
    {
        $this->withExceptionHandling();

        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        // The membership was valid when redirect() stashed the tenant id in
        // session, but the user was removed from the tenant before the OAuth
        // round trip completed -- the callback must re-check membership, not
        // trust the session value alone.
        $tenant->users()->detach($user);

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $response->assertForbidden();
    }

    public function test_callback_handles_a_declined_oauth_grant_gracefully(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        Socialite::shouldReceive('driver')
            ->with('github')
            ->andReturnSelf();
        Socialite::shouldReceive('user')->andThrow(new \Exception('access_denied'));

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $response->assertRedirect(route('filament.dashboard.pages.git-connections', ['tenant' => $tenant]));
        $response->assertSessionHasErrors('git_connection');
        $this->assertDatabaseMissing('tenant_git_connections', [
            'tenant_id' => $tenant->id,
            'provider' => 'github',
        ]);
    }

    public function test_redirect_rejects_a_provider_that_is_not_a_git_provider(): void
    {
        $this->withExceptionHandling();

        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        // The route accepts any social-login provider name for {provider};
        // only github/gitlab/bitbucket are valid once intent=git_connection.
        $response = $this->actingAs($user)
            ->get("/auth/google/redirect?intent=git_connection&tenant_id={$tenant->id}");

        $response->assertNotFound();
    }

    public function test_redirect_rejects_an_array_valued_tenant_id(): void
    {
        $this->withExceptionHandling();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/auth/github/redirect?intent=git_connection&tenant_id[]=1');

        $response->assertForbidden();
    }

    public function test_a_successful_connect_shows_a_correctly_capitalized_success_message_on_the_page(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => 'glpat_callback_token']);
        Socialite::shouldReceive('driver')->with('gitlab')->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialiteUser);

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/gitlab/callback');

        $response->assertSessionHas('status', 'GitLab connected.');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('GitLab connected.');
    }

    public function test_a_declined_grant_shows_the_error_message_on_the_page(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        Socialite::shouldReceive('driver')->with('github')->andReturnSelf();
        Socialite::shouldReceive('user')->andThrow(new \Exception('access_denied'));

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Connection was cancelled or failed. Please try again.');
    }
}
