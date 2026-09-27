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

        $this->actingAs($user)
            ->withSession(['git_connection_tenant_id' => $tenant->id])
            ->get('/auth/github/callback');

        $this->assertDatabaseHas('tenant_git_connections', [
            'tenant_id' => $tenant->id,
            'provider' => 'github',
            'account_login' => 'octocat',
        ]);
    }
}
