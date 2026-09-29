<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
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

    /**
     * One page (RepositoryPage::PAGE_SIZE) of the repositories the connection's token can
     * see, optionally narrowed by a name search. Listings are cached briefly per connection.
     *
     * @throws GitRepositoryListingRejectedException when the provider refuses the listing
     *                                               (revoked token, missing scope): reconnect
     * @throws GitAccessTemporarilyUnavailableException on a transient failure (network,
     *                                                  provider 5xx, rate limit)
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage;

    /**
     * The HTTP basic-auth pair git presents to this provider for the connection.
     * The single source of truth for the username; the password is the access
     * token, so callers must never place it in argv, a URL, a log or on disk.
     *
     * @return array{username:string, password:string}
     */
    public function cloneCredentials(TenantGitConnection $connection): array;

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
