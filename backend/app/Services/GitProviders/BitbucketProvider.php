<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BitbucketProvider implements GitProvider
{
    public function name(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function authorizationScopes(): array
    {
        return ['repository'];
    }

    public function listBranches(TenantGitConnection $connection, string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        /** @var list<string> */
        return Cache::remember(
            "bitbucket_branches:{$connection->id}:{$repo['workspace']}/{$repo['slug']}",
            now()->addMinutes(15),
            function () use ($connection, $repo): array {
                try {
                    $response = Http::timeout(10)->connectTimeout(5)
                        ->withToken($connection->access_token)
                        ->get("https://api.bitbucket.org/2.0/repositories/{$repo['workspace']}/{$repo['slug']}/refs/branches")
                        ->throw();

                    return collect($response->json('values'))->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    public function cloneUrl(TenantGitConnection $connection, string $repoUrl): string
    {
        return 'https://x-token-auth:'.$connection->access_token.'@'.substr($repoUrl, strlen('https://'));
    }

    /** @return array{workspace:string,slug:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#bitbucket\.org[:/]([^/]+)/(.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['workspace' => $matches[1], 'slug' => $matches[2]];
    }
}
