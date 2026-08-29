<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubApiClient
{
    /** @return list<string> */
    public function listBranches(string $repoUrl): array
    {
        $repo = $this->parseRepo($repoUrl);

        if ($repo === null) {
            return [];
        }

        try {
            $request = Http::timeout(10)->connectTimeout(5);

            $token = (string) config('audit.github_token');
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
                ->get("https://api.github.com/repos/{$repo['owner']}/{$repo['name']}/branches", ['per_page' => 100])
                ->throw();

            return collect($response->json())->pluck('name')->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
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
