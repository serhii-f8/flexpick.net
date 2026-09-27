<?php

namespace App\Services\GitProviders;

use App\Models\Tenant;
use App\Models\TenantGitConnection;

class GitRepoAccessResolver
{
    public function __construct(private GitProviderResolver $providers) {}

    /**
     * The URL git should use for this tenant: their own authenticated clone URL when
     * they have a connection for the repo's provider, otherwise the URL unchanged.
     *
     * A miss never throws. A tenant with no connection is cloned anonymously, exactly
     * like a tenantless landing request: a public repo succeeds, a private one fails
     * git's own reachability check in RepositoryCloner::preflight() and takes the
     * ordinary not-reachable path. Landing-page requests routinely carry a tenant
     * (stamped at submission, or claimed after payment), so a hard "connect first"
     * rule here would reject public repos that production accepts. The proactive
     * gate belongs only where we are about to charge: AuditReports::launchAudit().
     *
     * The lookup itself stays tenant-scoped (connectionFor()), so another tenant's
     * connection can never be attached to this tenant's request.
     */
    public function resolveCloneUrl(string $repoUrl, ?Tenant $tenant): string
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null || $tenant === null || ! $this->isCanonicalHttpsUrl($repoUrl)) {
            return $repoUrl;
        }

        $connection = $this->connectionFor($repoUrl, $tenant);

        if ($connection === null) {
            return $repoUrl;
        }

        return $provider->cloneUrl($connection, $repoUrl);
    }

    /**
     * A provider is resolved by host alone (see GitProviderResolver::forUrl()), but every
     * provider's cloneUrl() strips a literal 8-character "https://" prefix before embedding
     * the tenant's token. A URL that isn't canonically https:// (wrong scheme, embedded
     * userinfo, or an explicit port) would have that naive strip corrupt the host, sending
     * the tenant's real token to the wrong domain. Treat anything non-canonical the same as
     * an unrecognized host: pass it through unchanged, no credentials attached.
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

    public function connectionFor(string $repoUrl, Tenant $tenant): ?TenantGitConnection
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null) {
            return null;
        }

        return TenantGitConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider->name())
            ->first();
    }
}
