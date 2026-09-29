<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GitProviders\GitConnectionService;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\FeatureTest;

class GitConnectionOAuthTest extends FeatureTest
{
    /** @return array{0: User, 1: Tenant} a member holding the settings permission */
    private function permittedMember(): array
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS]);

        return [$user, $tenant];
    }

    private function plantNonce(User $user, Tenant $tenant, string $provider = 'github'): string
    {
        return app(GitConnectionService::class)->beginConnect($tenant, $user, $provider);
    }

    private function fakeProviderUser(string $driver, string $token = 'ghp_callback_token'): void
    {
        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => $token]);
        Socialite::shouldReceive('driver')->with($driver)->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialiteUser);
    }

    public function test_redirect_requires_authentication(): void
    {
        $this->withExceptionHandling();

        $this->get('/auth/github/redirect?intent=git_connection&nonce=whatever')->assertForbidden();
    }

    public function test_a_plain_get_with_a_tenant_id_and_no_nonce_never_reaches_the_provider(): void
    {
        $this->withExceptionHandling();

        [$user, $tenant] = $this->permittedMember();
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->get("/auth/github/redirect?intent=git_connection&tenant_id={$tenant->id}")
            ->assertForbidden();
    }

    public function test_an_unknown_nonce_is_rejected(): void
    {
        $this->withExceptionHandling();

        [$user] = $this->permittedMember();
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->get('/auth/github/redirect?intent=git_connection&nonce=not-a-real-nonce')
            ->assertForbidden();
    }

    public function test_a_nonce_planted_for_one_user_cannot_be_replayed_by_another(): void
    {
        $this->withExceptionHandling();

        [$userA, $tenant] = $this->permittedMember();
        $userB = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS]);
        $nonce = $this->plantNonce($userA, $tenant);
        Socialite::shouldReceive('driver')->never();

        // Same session store, different authenticated user.
        $this->actingAs($userB)
            ->withSession(session()->all())
            ->get("/auth/github/redirect?intent=git_connection&nonce={$nonce}")
            ->assertForbidden();
    }

    public function test_a_nonce_cannot_be_reused(): void
    {
        $this->withExceptionHandling();

        [$user, $tenant] = $this->permittedMember();
        $nonce = $this->plantNonce($user, $tenant);

        Socialite::shouldReceive('driver')->with('github')->once()->andReturnSelf();
        Socialite::shouldReceive('scopes')->once()->andReturnSelf();
        Socialite::shouldReceive('redirect')->once()->andReturn(redirect('https://github.com/login/oauth/authorize'));

        $this->actingAs($user)->withSession(session()->all());

        $this->get("/auth/github/redirect?intent=git_connection&nonce={$nonce}")
            ->assertRedirect('https://github.com/login/oauth/authorize');
        $this->assertSame($tenant->id, session('git_connection_tenant_id'));

        $this->get("/auth/github/redirect?intent=git_connection&nonce={$nonce}")->assertForbidden();
    }

    public function test_a_nonce_for_one_provider_cannot_be_used_on_another(): void
    {
        $this->withExceptionHandling();

        [$user, $tenant] = $this->permittedMember();
        $nonce = $this->plantNonce($user, $tenant, 'github');
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->withSession(session()->all())
            ->get("/auth/gitlab/redirect?intent=git_connection&nonce={$nonce}")
            ->assertForbidden();
    }

    public function test_redirect_rejects_a_user_who_lost_membership_or_permission_after_the_nonce_was_issued(): void
    {
        $this->withExceptionHandling();

        [$user, $tenant] = $this->permittedMember();
        $nonce = $this->plantNonce($user, $tenant);
        $tenant->users()->detach($user);
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->withSession(session()->all())
            ->get("/auth/github/redirect?intent=git_connection&nonce={$nonce}")
            ->assertForbidden();
    }

    public function test_redirect_rejects_a_provider_that_is_not_a_git_provider(): void
    {
        $this->withExceptionHandling();

        [$user, $tenant] = $this->permittedMember();
        $nonce = $this->plantNonce($user, $tenant, 'google');

        $this->actingAs($user)
            ->withSession(session()->all())
            ->get("/auth/google/redirect?intent=git_connection&nonce={$nonce}")
            ->assertNotFound();
    }

    public function test_redirect_rejects_an_array_valued_nonce(): void
    {
        $this->withExceptionHandling();

        [$user] = $this->permittedMember();

        $this->actingAs($user)
            ->get('/auth/github/redirect?intent=git_connection&nonce[]=1')
            ->assertForbidden();
    }

    public function test_callback_stores_the_connection_for_the_authorized_tenant(): void
    {
        [$user, $tenant] = $this->permittedMember();
        $this->fakeProviderUser('github');

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

        [$user, $tenant] = $this->permittedMember();
        $tenant->users()->detach($user);
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback')
            ->assertForbidden();
    }

    public function test_callback_rejects_a_member_without_the_settings_permission(): void
    {
        $this->withExceptionHandling();

        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant);
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback')
            ->assertForbidden();

        $this->assertDatabaseMissing('tenant_git_connections', ['tenant_id' => $tenant->id]);
    }

    public function test_callback_handles_a_declined_oauth_grant_gracefully(): void
    {
        [$user, $tenant] = $this->permittedMember();

        Socialite::shouldReceive('driver')->with('github')->andReturnSelf();
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

    public function test_a_successful_connect_shows_a_correctly_capitalized_success_message_on_the_page(): void
    {
        [$user, $tenant] = $this->permittedMember();
        $this->fakeProviderUser('gitlab', 'glpat_callback_token');

        $response = $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/gitlab/callback');

        $response->assertSessionHas('status', 'GitLab connected.');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('GitLab connected.');
    }

    public function test_a_failed_callback_logs_the_provider_and_reason(): void
    {
        [$user, $tenant] = $this->permittedMember();

        Socialite::shouldReceive('driver')->with('bitbucket')->andReturnSelf();
        Socialite::shouldReceive('user')->andThrow(new \RuntimeException('Client error: 403 insufficient scope'));

        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Git connection OAuth callback failed.'
            && $context['provider'] === 'bitbucket'
            && $context['tenant_id'] === $tenant->id
            && $context['exception'] === \RuntimeException::class
            && str_contains($context['message'], '403 insufficient scope'));

        $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/bitbucket/callback')
            ->assertRedirect();
    }

    public function test_a_declined_grant_shows_the_error_message_on_the_page(): void
    {
        [$user, $tenant] = $this->permittedMember();

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
