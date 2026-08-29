<?php

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Cache;
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

        // The audit reports page fires one of these per scheduled repo row on
        // every page load, and the rate-limit budget being spent belongs to
        // the one shared PAT, across all tenants. Branch lists rarely change,
        // so 15 minutes of staleness is a fair trade for not making a user
        // with several scheduled repos wait on that many sequential calls to
        // api.github.com each time they open the page.
        //
        // Caching the [] miss is deliberate too: a genuinely inaccessible repo
        // should not be hammered once per render either. A thrown failure is
        // never cached -- the catch is inside the closure, so only its return
        // value is stored.
        /** @var list<string> */
        return Cache::remember(
            "github_branches:{$repo['owner']}/{$repo['name']}",
            now()->addMinutes(15),
            function () use ($repo): array {
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
            },
        );
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
