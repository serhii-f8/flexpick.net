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

        if ($provider === null || $tenant === null) {
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
