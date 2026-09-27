<?php

namespace App\Services\GitProviders;

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
}
