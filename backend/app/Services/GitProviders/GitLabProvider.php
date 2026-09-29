<?php

namespace App\Services\GitProviders;

use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GitLabProvider implements GitProvider
{
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
