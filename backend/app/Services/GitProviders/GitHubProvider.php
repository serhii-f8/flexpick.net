<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Models\TenantGitConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubProvider implements GitProvider
{
    use GuardsRepositoryListing;

    private const API_HOST = 'api.github.com';

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

    public function cloneCredentials(TenantGitConnection $connection): array
    {
        return ['username' => 'x-access-token', 'password' => $connection->access_token];
    }

    /**
     * GitHub OAuth App tokens do not expire, so a GitHub connection never has an
     * expires_at and this is never reached in practice. OAuth Apps have no
     * refresh_token grant at all; should an expiring token ever appear (e.g. a future
     * GitHub App migration), null makes the resolver drop the connection and ask the
     * tenant to reconnect -- the safe outcome for a token we cannot renew.
     */
    public function refreshToken(TenantGitConnection $connection): ?array
    {
        return null;
    }

    /**
     * The token's repositories, most recently pushed first. GitHub has no name search on
     * this endpoint, so the listing (up to max_pages x 100 repos) is fetched once per
     * connection, cached, and filtered/paged locally.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage
    {
        $key = "github_repos:{$connection->id}";
        $entries = $this->cachedEntries(Cache::get($key));

        if ($entries === null) {
            $rows = $this->fetchRepositories($connection);
            Cache::put($key, $rows, now()->addSeconds((int) config('audit.repo_picker.cache_seconds')));
            $entries = array_map(RepositoryEntry::fromArray(...), $rows);
        }

        return RepositoryPage::fromEntries($entries, $search, $page);
    }

    /**
     * A malformed cached payload is treated as a miss rather than an error.
     *
     * @return list<RepositoryEntry>|null
     */
    private function cachedEntries(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        $entries = [];

        foreach ($cached as $row) {
            if (! is_array($row) || ! isset($row['fullName'], $row['url'])) {
                return null;
            }

            $entries[] = RepositoryEntry::fromArray($row);
        }

        return $entries;
    }

    /** @return list<array{fullName: string, url: string, private: bool, defaultBranch: ?string, updatedAt: ?string}> */
    private function fetchRepositories(TenantGitConnection $connection): array
    {
        $rows = [];
        $url = 'https://'.self::API_HOST.'/user/repos';
        $query = [
            'affiliation' => 'owner,collaborator,organization_member',
            'sort' => 'pushed',
            'direction' => 'desc',
            'per_page' => 100,
        ];

        for ($fetched = 0; $fetched < (int) config('audit.repo_picker.max_pages'); $fetched++) {
            try {
                $request = Http::timeout(10)->connectTimeout(5)->withToken($connection->access_token);
                // An empty query array would replace the query string the next link carries.
                $response = $query === [] ? $request->get($url) : $request->get($url, $query);
            } catch (ConnectionException) {
                throw $this->listingUnavailable('GitHub');
            }

            $this->guardListingResponse($response, 'GitHub');

            foreach ($response->json() ?? [] as $repo) {
                if (! is_array($repo) || ! isset($repo['full_name'], $repo['html_url'])) {
                    continue;
                }

                $rows[] = (new RepositoryEntry(
                    $repo['full_name'],
                    $repo['html_url'],
                    (bool) ($repo['private'] ?? false),
                    $repo['default_branch'] ?? null,
                    $repo['pushed_at'] ?? null,
                ))->toArray();
            }

            $next = $this->nextLinkUrl($response->header('Link'));

            // Never carry the bearer token to a host other than the API host.
            if ($next === null || ! $this->isApiUrl($next)) {
                break;
            }

            $url = $next;
            $query = []; // the next link already carries its own query string
        }

        return $rows;
    }

    private function isApiUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower($parts['host'] ?? '') === self::API_HOST
            && ! isset($parts['user'], $parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    /** @return array{owner:string,name:string}|null */
    private function parseRepo(string $repoUrl): ?array
    {
        if (! preg_match('#github\.com[:/]([^/]+)/(.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return ['owner' => $matches[1], 'name' => $matches[2]];
    }
}
