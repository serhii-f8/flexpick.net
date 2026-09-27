<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitTokenRefreshUnavailableException;
use App\Models\TenantGitConnection;

interface GitProvider
{
    public function name(): string;

    public function label(): string;

    /** @return list<string> */
    public function authorizationScopes(): array;

    /** @return list<string> */
    public function listBranches(TenantGitConnection $connection, string $repoUrl): array;

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string;

    /**
     * Attempt to refresh an expired token using the connection's refresh_token.
     * Returns the new token data on success, or null if refresh isn't possible
     * or the provider rejected it (a revoked/invalid refresh_token, no
     * refresh_token on file, etc).
     *
     * @return array{access_token:string, refresh_token:?string, expires_in:?int}|null
     *
     * @throws GitTokenRefreshUnavailableException when the refresh could not be
     *                                             completed for a transient reason
     *                                             (network error, provider 5xx) --
     *                                             the connection may still be valid
     */
    public function refreshToken(TenantGitConnection $connection): ?array;
}
