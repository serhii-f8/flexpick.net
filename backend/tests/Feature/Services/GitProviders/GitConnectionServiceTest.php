<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use App\Services\GitProviders\GitConnectionService;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\FeatureTest;

class GitConnectionServiceTest extends FeatureTest
{
    public function test_stores_a_new_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $socialiteUser = (new SocialiteUser)->setRaw([])->map([
            'nickname' => 'octocat',
            'token' => 'ghp_new_token',
            'refreshToken' => 'refresh_token_value',
            'expiresIn' => 28800,
        ]);

        $connection = app(GitConnectionService::class)->store($tenant, $user, 'github', $socialiteUser);

        $this->assertSame($tenant->id, $connection->tenant_id);
        $this->assertSame('github', $connection->provider);
        $this->assertSame('octocat', $connection->account_login);
        $this->assertSame('ghp_new_token', $connection->access_token);
        $this->assertSame('refresh_token_value', $connection->refresh_token);
        $this->assertSame($user->id, $connection->connected_by_user_id);
        $this->assertNotNull($connection->expires_at);
    }

    public function test_reconnecting_replaces_the_existing_connection(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $existing = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'access_token' => 'old_token']);
        $socialiteUser = (new SocialiteUser)->setRaw([])->map(['nickname' => 'octocat', 'token' => 'new_token']);

        $connection = app(GitConnectionService::class)->store($tenant, $user, 'github', $socialiteUser);

        $this->assertSame($existing->id, $connection->id);
        $this->assertSame('new_token', $connection->fresh()->access_token);
    }
}
