<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one door the dashboard's repository picker goes through. Listing a connected
 * account's repositories is an instant, repeatable "can this account see owner/repo"
 * oracle if left open, so every call here is limited to members who may manage the
 * workspace's git connections, uses only that workspace's own connection, is
 * rate-limited per user, and takes a provider NAME (never a URL, host or token)
 * from the caller.
 */
class RepositoryPicker
{
    public function __construct(
        private GitConnectionService $connections,
        private GitRepoAccessResolver $access,
        private GitProviderResolver $providers,
    ) {}

    /**
     * The providers this workspace has a stored connection for, in a fixed order; empty
     * for a user who may not manage connections (they get no picker at all).
     *
     * @return list<string>
     */
    public function providersFor(?Tenant $tenant, User $user): array
    {
        if ($tenant === null || ! $this->connections->userMayManage($tenant, $user)) {
            return [];
        }

        $stored = TenantGitConnection::query()->where('tenant_id', $tenant->id)->pluck('provider')->all();

        return array_values(array_filter(
            GitProviderResolver::KNOWN_PROVIDER_NAMES,
            fn (string $name): bool => in_array($name, $stored, true),
        ));
    }

    /** @throws AuthorizationException when the user may not manage the workspace's git connections */
    public function list(?Tenant $tenant, User $user, string $providerName, ?string $search, int $page): RepositoryPickerResult
    {
        if ($tenant === null || ! $this->connections->userMayManage($tenant, $user)) {
            throw new AuthorizationException;
        }

        if (! in_array($providerName, GitProviderResolver::KNOWN_PROVIDER_NAMES, true)) {
            return RepositoryPickerResult::of(RepositoryPickerState::NoConnection);
        }

        $limiterKey = "repo-picker:{$user->id}";

        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('audit.repo_picker.rate_limit_per_minute'))) {
            return RepositoryPickerResult::of(RepositoryPickerState::Throttled);
        }

        RateLimiter::hit($limiterKey, 60);

        try {
            $hadConnection = TenantGitConnection::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', $providerName)
                ->exists();

            $connection = $this->access->connectionForProvider($providerName, $tenant);

            if ($connection === null) {
                // A connection that existed but came back null had its refresh rejected
                // (invalid_grant) and was deleted: that calls for a reconnect, not a first connect.
                return RepositoryPickerResult::of($hadConnection ? RepositoryPickerState::Reconnect : RepositoryPickerState::NoConnection);
            }

            return RepositoryPickerResult::ok(
                $this->providers->forProviderName($providerName)->listRepositories($connection, $this->cleanSearch($search), max(1, $page)),
            );
        } catch (GitAccessTemporarilyUnavailableException) {
            return RepositoryPickerResult::of(RepositoryPickerState::Unavailable);
        } catch (GitRepositoryListingRejectedException) {
            return RepositoryPickerResult::of(RepositoryPickerState::Reconnect);
        }
    }

    /**
     * Whether $url is one of the repositories the account lists on this page. A launch
     * form's repoUrl is a client-editable Livewire property, so a picked URL is only
     * trusted after the listing itself vouches for it.
     */
    public function isListed(?Tenant $tenant, User $user, string $providerName, ?string $search, int $page, string $url): bool
    {
        $result = $this->list($tenant, $user, $providerName, $search, $page);

        if ($result->state !== RepositoryPickerState::Ok) {
            return false;
        }

        foreach ($result->page->items as $entry) {
            if ($entry->url === $url) {
                return true;
            }
        }

        return false;
    }

    private function cleanSearch(?string $search): ?string
    {
        $search = mb_substr(trim((string) $search), 0, (int) config('audit.repo_picker.max_search_length'));

        return $search === '' ? null : $search;
    }
}
