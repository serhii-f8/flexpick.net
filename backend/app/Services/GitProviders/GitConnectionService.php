<?php

namespace App\Services\GitProviders;

use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use App\Services\TenantPermissionService;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GitConnectionService
{
    private const NONCE_SESSION_KEY = 'git_connection_nonces';

    private const NONCE_TTL_MINUTES = 10;

    public function __construct(private TenantPermissionService $tenantPermissionService) {}

    /**
     * Connecting, replacing and disconnecting a workspace's git account all
     * need the same tenant-settings permission the workspace settings page uses.
     */
    public function userMayManage(?Tenant $tenant, User $user): bool
    {
        return $tenant !== null
            && $user->tenants()->where('tenants.id', $tenant->id)->exists()
            && $this->tenantPermissionService->tenantUserHasPermissionTo(
                $tenant,
                $user,
                TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS
            );
    }

    /**
     * Issues a single-use nonce bound to {user, tenant, provider} in the
     * session. The OAuth redirect carries only this nonce, never a tenant id,
     * so the workspace can't be chosen by a crafted link -- it can only be
     * chosen by a Livewire action, which is CSRF-protected.
     */
    public function beginConnect(Tenant $tenant, User $user, string $provider): string
    {
        $nonce = Str::random(40);

        $nonces = $this->liveNonces();
        $nonces[$nonce] = [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'expires_at' => now()->addMinutes(self::NONCE_TTL_MINUTES)->getTimestamp(),
        ];
        session([self::NONCE_SESSION_KEY => $nonces]);

        return $nonce;
    }

    /**
     * Consumes the nonce (whether or not it matches) and returns the bound
     * tenant only when it was issued to this user for this provider and the
     * user may still manage that workspace's git connections.
     */
    public function consumeConnectNonce(mixed $nonce, User $user, string $provider): ?Tenant
    {
        if (! is_string($nonce) || $nonce === '') {
            return null;
        }

        $nonces = $this->liveNonces();
        $binding = $nonces[$nonce] ?? null;
        unset($nonces[$nonce]);
        session([self::NONCE_SESSION_KEY => $nonces]);

        if ($binding === null
            || $binding['user_id'] !== $user->id
            || $binding['provider'] !== $provider) {
            return null;
        }

        $tenant = Tenant::find($binding['tenant_id']);

        return $this->userMayManage($tenant, $user) ? $tenant : null;
    }

    /** @return array<string, array{user_id: int, tenant_id: int, provider: string, expires_at: int}> */
    private function liveNonces(): array
    {
        $now = now()->getTimestamp();

        return array_filter(
            (array) session(self::NONCE_SESSION_KEY, []),
            fn (array $binding) => $binding['expires_at'] >= $now
        );
    }

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
