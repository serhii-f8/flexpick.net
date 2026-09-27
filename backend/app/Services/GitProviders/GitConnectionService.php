<?php

namespace App\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GitConnectionService
{
    public function store(Tenant $tenant, User $user, string $provider, SocialiteUser $socialiteUser): TenantGitConnection
    {
        return TenantGitConnection::updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => $provider],
            [
                'account_login' => $socialiteUser->getNickname() ?: $socialiteUser->getName(),
                'access_token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'expires_at' => $socialiteUser->expiresIn ? now()->addSeconds($socialiteUser->expiresIn) : null,
                'connected_by_user_id' => $user->id,
                'connected_at' => now(),
            ]
        );
    }
}
