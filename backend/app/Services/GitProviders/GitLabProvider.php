<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Models\TenantGitConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GitLabProvider implements GitProvider
{
    use GuardsRepositoryListing;
    use InterpretsTokenRefreshResponses;

    public function name(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    /**
     * read_repository covers Git-over-HTTP (clone); read_api is needed for the REST API
     * that listBranches() calls -- read_repository alone gets a 403 there.
     */
    public function authorizationScopes(): array
    {
        return ['read_repository', 'read_api'];
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

    /**
     * One page of the token's projects, most recently active first. GitLab searches and
     * paginates server-side, so each (search, page) is cached on its own. A malformed
     * cached payload is treated as a miss.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage
    {
        $term = mb_strtolower(trim((string) $search));
        $page = max(1, $page);
        $key = "gitlab_repos:{$connection->id}:".md5($term).":{$page}";

        $cached = $this->cachedPage(Cache::get($key));

        if ($cached === null) {
            $cached = $this->fetchRepositoryPage($connection, $term, $page);

            // An empty page is not cached: a project created a moment later should show up at once.
            if ($cached['items'] !== []) {
                Cache::put($key, $cached, now()->addSeconds((int) config('audit.repo_picker.cache_seconds')));
            }
        }

        return new RepositoryPage(array_map(RepositoryEntry::fromArray(...), $cached['items']), $cached['has_more']);
    }

    /** @return array{items: list<array<string, mixed>>, has_more: bool}|null */
    private function cachedPage(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_bool($cached['has_more'] ?? null) || ! is_array($cached['items'] ?? null)) {
            return null;
        }

        foreach ($cached['items'] as $row) {
            if (! is_array($row) || ! isset($row['fullName'], $row['url'])) {
                return null;
            }
        }

        /** @var array{items: list<array<string, mixed>>, has_more: bool} */
        return $cached;
    }

    /** @return array{items: list<array<string, mixed>>, has_more: bool} */
    private function fetchRepositoryPage(TenantGitConnection $connection, string $term, int $page): array
    {
        $query = [
            'membership' => 'true',
            'archived' => 'false',
            'order_by' => 'last_activity_at',
            'sort' => 'desc',
            'per_page' => RepositoryPage::PAGE_SIZE,
            'page' => $page,
        ];

        if ($term !== '') {
            $query['search'] = $term;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withToken($connection->access_token)
                ->get('https://gitlab.com/api/v4/projects', $query);
        } catch (ConnectionException) {
            throw $this->listingUnavailable('GitLab');
        }

        $this->guardListingResponse($response, 'GitLab');

        $items = [];

        foreach ($response->json() ?? [] as $project) {
            if (! isset($project['path_with_namespace'], $project['web_url'])) {
                continue;
            }

            $items[] = (new RepositoryEntry(
                $project['path_with_namespace'],
                $project['web_url'],
                ($project['visibility'] ?? 'private') !== 'public', // internal counts as private
                $project['default_branch'] ?? null,
                $project['last_activity_at'] ?? null,
            ))->toArray();
        }

        return ['items' => $items, 'has_more' => trim((string) $response->header('X-Next-Page')) !== ''];
    }

    public function cloneCredentials(TenantGitConnection $connection): array
    {
        return ['username' => 'oauth2', 'password' => $connection->access_token];
    }

    /**
     * GitLab OAuth2 refresh_token grant: POST https://gitlab.com/oauth/token with form
     * params client_id, client_secret, refresh_token, grant_type=refresh_token and the
     * same redirect_uri used at authorization. GitLab rotates the refresh token on every
     * refresh (the old one is revoked), so the response's refresh_token must be stored.
     */
    public function refreshToken(TenantGitConnection $connection): ?array
    {
        if (blank($connection->refresh_token)) {
            return null;
        }

        $redirect = (string) config('services.gitlab.redirect');

        return $this->interpretRefreshResponse(fn () => Http::timeout(10)->connectTimeout(5)
            ->asForm()
            ->post('https://gitlab.com/oauth/token', [
                'client_id' => config('services.gitlab.client_id'),
                'client_secret' => config('services.gitlab.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
                // Socialite resolves a relative redirect the same way (SocialiteManager::formatRedirectUrl()).
                'redirect_uri' => Str::startsWith($redirect, '/') ? url($redirect) : $redirect,
            ]));
    }

    private function parseRepo(string $repoUrl): ?string
    {
        if (! preg_match('#gitlab\.com[:/](.+?)(?:\.git)?/?$#i', $repoUrl, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
