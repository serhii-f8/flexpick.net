<?php

namespace App\Services\GitProviders;

use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;

class GitRepoAccessResolver
{
    public function __construct(private GitProviderResolver $providers) {}

    public function resolveCloneUrl(string $repoUrl, ?Tenant $tenant): string
    {
        $provider = $this->providers->forUrl($repoUrl);

        if ($provider === null || $tenant === null || ! $this->isCanonicalHttpsUrl($repoUrl)) {
            return $repoUrl;
        }

        $connection = $this->connectionFor($repoUrl, $tenant);

        if ($connection === null) {
            throw AuditNotAnalyzableException::accessDenied(
                "Connect your {$provider->label()} account to audit this repository."
            );
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
