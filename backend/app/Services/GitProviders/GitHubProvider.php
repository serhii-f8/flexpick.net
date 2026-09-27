<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubProvider implements GitProvider
{
    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function authorizationScopes(): array
    {
        return ['repo'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "github_branches:{$connection->id}:{$repo['owner']}/{$repo['name']}",
            now()->addMinutes(15),
            function () use ($connection, $repo): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/branches", ['per_page' => 100])
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
        return 'https://x-access-token:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    /** @return array{owner:string,name:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#github\.com[:/]([^/]+)/([^/.]+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['owner' => $matches[1], 'name' => $matches[2]];
    }
}
