<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Models\TenantGitConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class BitbucketProvider implements GitProvider
{
    use GuardsRepositoryListing;
    use InterpretsTokenRefreshResponses;

    private const MAX_WORKSPACES = 5;

    public function name(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    /**
     * `repository` covers clone and the branch/repository REST calls. `account` is needed
     * only by the picker: Bitbucket has no per-token repository list, so it enumerates the
     * member's workspaces first (GET /2.0/workspaces). A connection made before `account`
     * was requested gets a 403 there and the picker asks the member to reconnect.
     * Deploy note: the OAuth consumer must grant "Account: Read" as well as "Repositories: Read".
     */
    public function authorizationScopes(): array
    {
        return ['repository', 'account'];
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
                        ->get("https://api.bitbucket.org/2.0/repositories/{$repo['workspace']}/{$repo['slug']}/refs/branches", ['pagelen' => 100])
                        ->throw();

                    return collect($response->json('values'))->pluck('name')->filter()->values()->all();
                } catch (Throwable) {
                    return [];
                }
            },
        );
    }

    /**
     * The member's repositories across their workspaces (at most MAX_WORKSPACES, 100
     * repositories each), newest update first, cached per connection and filtered/paged
     * locally. The deprecated global listing endpoints are deliberately not used.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage
    {
        $key = "bitbucket_repos:{$connection->id}";
        $rows = $this->cachedRows(Cache::get($key));

        if ($rows === null) {
            $rows = $this->fetchRepositories($connection);
            Cache::put($key, $rows, now()->addSeconds((int) config('audit.repo_picker.cache_seconds')));
        }

        return RepositoryPage::fromEntries(array_map(RepositoryEntry::fromArray(...), $rows), $search, $page);
    }

    /** @return list<array<string, mixed>>|null */
    private function cachedRows(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        foreach ($cached as $row) {
            if (! is_array($row) || ! isset($row['fullName'], $row['url'])) {
                return null;
            }
        }

        /** @var list<array<string, mixed>> */
        return array_values($cached);
    }

    /** @return list<array<string, mixed>> */
    private function fetchRepositories(TenantGitConnection $connection): array
    {
        $slugs = collect($this->get($connection, 'https://api.bitbucket.org/2.0/workspaces', ['role' => 'member', 'pagelen' => 20])->json('values') ?? [])
            ->pluck('slug')
            ->filter()
            ->take(self::MAX_WORKSPACES);

        $rows = [];

        foreach ($slugs as $slug) {
            $repos = $this->get($connection, 'https://api.bitbucket.org/2.0/repositories/'.rawurlencode($slug), [
                'role' => 'member',
                'sort' => '-updated_on',
                'pagelen' => 100,
            ])->json('values') ?? [];

            foreach ($repos as $repo) {
                if (! isset($repo['full_name'])) {
                    continue;
                }

                $rows[] = (new RepositoryEntry(
                    $repo['full_name'],
                    'https://bitbucket.org/'.$repo['full_name'],
                    (bool) ($repo['is_private'] ?? true),
                    $repo['mainbranch']['name'] ?? null,
                    $repo['updated_on'] ?? null,
                ))->toArray();
            }
        }

        // Newest first across workspaces; ISO-8601 strings in one timezone sort lexically.
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));

        return $rows;
    }

    /** @param array<string, mixed> $query */
    private function get(TenantGitConnection $connection, string $url, array $query): Response
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withToken($connection->access_token)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw $this->listingUnavailable('Bitbucket');
        }

        $this->guardListingResponse($response, 'Bitbucket');

        return $response;
    }

    public function cloneCredentials(TenantGitConnection $connection): array
    {
        return ['username' => 'x-token-auth', 'password' => $connection->access_token];
    }

    /**
     * Bitbucket Cloud OAuth2 refresh_token grant: POST
     * https://bitbucket.org/site/oauth2/access_token, HTTP Basic auth with the OAuth
     * consumer's key:secret, form params grant_type=refresh_token and refresh_token.
     */
    public function refreshToken(TenantGitConnection $connection): ?array
    {
        if (blank($connection->refresh_token)) {
            return null;
        }

        return $this->interpretRefreshResponse(fn () => Http::timeout(10)->connectTimeout(5)
            ->asForm()
            ->withBasicAuth((string) config('services.bitbucket.client_id'), (string) config('services.bitbucket.client_secret'))
            ->post('https://bitbucket.org/site/oauth2/access_token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]));
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
