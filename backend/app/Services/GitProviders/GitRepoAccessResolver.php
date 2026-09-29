<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitTokenRefreshUnavailableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class GitRepoAccessResolver
{
    /**
     * Refresh a token this close to expiry rather than hand git one that dies mid-clone.
     */
    private const EXPIRY_LEEWAY_SECONDS = 60;

    private const REFRESH_LOCK_TTL_SECONDS = 60;

    public function __construct(private GitProviderResolver $providers) {}

    /**
     * The credential git should present for this tenant: their own connection for the
     * repo's provider, or null (clone anonymously) when there is none.
     *
     * A miss never throws. A tenant with no connection is cloned anonymously, exactly
     * like a tenantless landing request: a public repo succeeds, a private one fails
     * git's own reachability check in RepositoryCloner::preflight() and takes the
     * ordinary not-reachable path. Landing-page requests routinely carry a tenant
     * (stamped at submission, or claimed after payment), so a hard "connect first"
     * rule here would reject public repos that production accepts. The proactive
     * gate belongs only where we are about to charge: AuditReports::launchAudit().
     *
     * The one exception is a refresh that is only transiently unavailable: that throws
     * GitAccessTemporarilyUnavailableException, because cloning anonymously would fail a
     * private repo and be mistaken for "no access" -- closing and refunding a healthy
     * connection's audit.
     * The lookup itself stays tenant-scoped (connectionFor()), so another tenant's
     * connection can never be attached to this tenant's request.
     */
    public function resolveCredential(string $repoUrl, ?Tenant $tenant): ?GitCredential
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null || $tenant === null || ! $this->isCanonicalHttpsUrl($repoUrl)) {
            return null;
        }

        $connection = $this->freshConnection($repoUrl, $tenant);

        if ($connection === null) {
            return null;
        }

        $pair = $provider->cloneCredentials($connection);

        return new GitCredential(
            $pair['username'],
            $pair['password'],
            'https://'.strtolower((string) parse_url($repoUrl, PHP_URL_HOST)),
        );
    }

    /**
     * A provider is resolved by host alone (see GitProviderResolver::forUrl()). A URL that
     * isn't canonically https:// (wrong scheme, embedded userinfo, or an explicit port)
     * never gets the tenant's token: the credential is scoped to "https://<host>" and
     * would not even match such a URL. Treat anything non-canonical the same as an
     * unrecognized host: no credentials attached.
     */
    private function isCanonicalHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    /**
     * The tenant's connection for this repo's provider, with a usable (refreshed if
     * need be) token -- or null when there is none. A connection whose refresh the
     * provider rejected is deleted, so callers see exactly what they'd see had the
     * tenant never connected: the dashboard asks them to connect again.
     *
     * @throws GitAccessTemporarilyUnavailableException when the token needs a refresh that
     *                                                  is only transiently unavailable
     */
    public function connectionFor(string $repoUrl, Tenant $tenant): ?TenantGitConnection
    {
        return $this->freshConnection($repoUrl, $tenant);
    }

    private function freshConnection(string $repoUrl, Tenant $tenant): ?TenantGitConnection
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null) {
            return null;
        }

        $connection = TenantGitConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider->name())
            ->first();

        if ($connection === null || ! $this->isExpired($connection)) {
            return $connection;
        }

        // GitLab rotates the refresh_token on every use and revokes the old one, so two
        // workers refreshing the same connection at once would have the loser's refresh
        // rejected -- and a rejection deletes the connection. Serialize per connection,
        // and re-read inside the lock: the winner may already have refreshed it.
        //
        // The wait must outlast a refresh already in flight: its HTTP call can take
        // timeout 10s + connect 5s. The lock TTL stays above wait + one refresh.
        try {
            return Cache::lock("git_connection_refresh:{$connection->id}", self::REFRESH_LOCK_TTL_SECONDS)
                ->block((int) config('audit.git_refresh_lock_wait'), fn () => $this->refreshIfStillExpired($provider, $connection));
        } catch (LockTimeoutException) {
            throw new GitAccessTemporarilyUnavailableException('Git token refresh is in progress elsewhere; try again shortly');
        }
    }

    private function refreshIfStillExpired(GitProvider $provider, TenantGitConnection $stale): ?TenantGitConnection
    {
        $connection = $stale->fresh();

        if ($connection === null || ! $this->isExpired($connection)) {
            return $connection;
        }

        try {
            $refreshed = $provider->refreshToken($connection);
        } catch (GitTokenRefreshUnavailableException) {
            // Transient (network/5xx): keep the connection for the next attempt, and
            // don't hand out a token we know is expired. Not "unconnected" either:
            // callers must retry rather than treat a private repo as inaccessible.
            throw new GitAccessTemporarilyUnavailableException('Git token refresh is temporarily unavailable');
        }

        if ($refreshed === null) {
            $connection->delete();

            return null;
        }

        $connection->update([
            'access_token' => $refreshed['access_token'],
            'refresh_token' => $refreshed['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => $refreshed['expires_in'] !== null ? now()->addSeconds($refreshed['expires_in']) : null,
        ]);

        return $connection->fresh();
    }

    private function isExpired(TenantGitConnection $connection): bool
    {
        $expiresAt = $connection->expires_at; // 'datetime' cast: Carbon or null

        return $expiresAt instanceof CarbonInterface
            && $expiresAt->lte(now()->addSeconds(self::EXPIRY_LEEWAY_SECONDS));
    }
}
