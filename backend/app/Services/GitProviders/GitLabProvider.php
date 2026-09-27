<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitLabProvider implements GitProvider
{
    public function name(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function authorizationScopes(): array
    {
        return ['read_repository'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $path = $this->parseRepo($repoUrl);

        if ($path === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "gitlab_branches:{$connection->id}:{$path}",
            now()->addMinutes(15),
            function () use ($connection, $path): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get('https://gitlab.com/api/v4/projects/'.rawurlencode($path).'/repository/branches', ['per_page' => 100])
                        ->throw();

                    return collect($response->json())->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string
    {
        return 'https://oauth2:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    private function parseRepo(string $repoUrl): ?string
    {
        if (! preg_match('#gitlab\.com[:/](.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
