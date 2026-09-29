# Repository Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the dashboard "Run an audit" page, a member whose workspace has a connected GitHub, GitLab or Bitbucket account can pick a repository from that account instead of pasting a URL.

**Architecture:** Each git provider gets a `listRepositories()` method (live API call, cached ~5 minutes). A new `RepositoryPicker` service wraps it with permission, rate-limit and error-state handling and is the only thing the `AuditReports` Livewire page talks to. The page keeps its existing `repoUrl`/`branch` fields, so charging, sizing and the pipeline are untouched; the URL box stays as a fallback.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament 5, Livewire 4, PHPUnit 11 (never Pest), Laravel `Http` client + `Cache` + `RateLimiter`.

**Spec:** `backend/docs/superpowers/specs/2026-09-29-repository-picker-design.md`

## Global Constraints

- Run everything inside the dev container from the repo root: `docker compose exec -T laravel.test <cmd>` (container cwd is the backend app).
- Tests are PHPUnit (`php artisan make:test --phpunit`), never Pest. TDD: write the failing test first, record RED and GREEN in the report.
- `FeatureTest` seeds once per SUITE, not per class — rows and global Config flips leak across classes. Create your own tenants/users/connections with factories, restore any Config row you change, never assert on global counts. `Http::fake()` and `Cache` are per-test; use unique connection ids (factories) so cache keys never collide across tests.
- Gates before commit: focused tests, then the full suite once (`php artisan test --compact`), `vendor/bin/pint` then `vendor/bin/pint --test` (never `--dirty`), and `vendor/bin/phpstan analyse --no-progress` clean (0 errors).
- Business logic in Services; follow the surrounding code's comment density and idiom.
- Never put a git token in a log line, exception message, URL, cache key or cache value. Cache values hold only repo metadata (name, URL, visibility, default branch, timestamp).
- Commit with explicit `git add <paths>` only (unrelated modified/untracked files exist in the tree). One commit per task (fix rounds may add more), conventional-commit subject, ending with the two lines:
  `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_017MNZiEKdDD9DXsTqw9CjUR`
- Do NOT touch `OAuthController.php`, `GitConnectionOAuthTest.php` or `opencode.json` files (modified by something else).
- Provider API facts (verified against the providers' current docs on 2026-09-29):
  - GitHub `GET /user/repos`: params `affiliation`, `sort` (`pushed`), `direction`, `per_page` (max 100), `page`; no search parameter; pagination via the `Link: <…>; rel="next"` header; `repo` scope.
  - GitLab `GET /projects`: params `membership`, `archived`, `search` (case-insensitive substring on name/path/description), `order_by=last_activity_at`, `sort`, `per_page`, `page`; the `X-Next-Page` response header is empty on the last page; `read_api` scope.
  - Bitbucket Cloud: the global `GET /2.0/repositories` and `GET /2.0/user/permissions/repositories` are deprecated (the latter also needs the `account` scope). Use the workspace-scoped list: `GET /2.0/workspaces?role=member` (needs the `account` scope) then `GET /2.0/repositories/{workspace}?role=member&sort=-updated_on&pagelen=100`.

## Review Focus

1. **Repo-listing oracle:** picker methods called by a member without the tenant-settings permission, or with a forged `pickerProvider`/URL, must never return or accept anything (403 / ignored). Pinned in Task 5 (service) and Task 6 (page).
2. **Forged repo URL:** `chooseRepository()` must reject a URL that is not in the account's current listing (client can edit Livewire public props). Pinned in Task 6.
3. **Another workspace's connection:** the connection is always the current workspace's for the chosen provider. Pinned in Task 5.
4. **Expired/revoked token or missing Bitbucket `account` scope:** shows a reconnect state, never a 500 and never the "connect" copy for a connected account. Pinned in Tasks 2–5 (provider rejection → `Reconnect`) and Task 6.
5. **Provider outage / rate limit / GitHub secondary rate limit (403 with `X-RateLimit-Remaining: 0`):** shows "try again in a minute", URL box stays usable. Pinned in Tasks 2–5, 6.
6. **Empty results, missing optional fields (no default branch, no timestamps), and a search term with no matches:** empty state, no crash. Pinned in Tasks 1–4, 6.
7. **Deploy dependency:** the Bitbucket OAuth consumer must grant *Account: Read* in addition to *Repositories: Read*; existing Bitbucket connections lack the `account` scope until the member reconnects (the picker shows the reconnect state meanwhile). Recorded in Task 4 and the final task's spec update.

---

### Task 1: Listing value objects, shared response guard, and config

**Files:**
- Create: `app/Services/GitProviders/RepositoryEntry.php`
- Create: `app/Services/GitProviders/RepositoryPage.php`
- Create: `app/Services/GitProviders/GuardsRepositoryListing.php` (trait)
- Create: `app/Exceptions/GitRepositoryListingRejectedException.php`
- Modify: `config/audit.php` (add the `repo_picker` block after `git_refresh_lock_wait`)
- Test: `tests/Feature/Services/GitProviders/RepositoryPageTest.php`, `tests/Feature/Services/GitProviders/GuardsRepositoryListingTest.php`

**Interfaces:**
- Produces:
  - `RepositoryEntry(string $fullName, string $url, bool $private, ?string $defaultBranch, ?string $updatedAt)` with `toArray(): array` and `static fromArray(array $row): self` (`updatedAt` is an ISO-8601 string so cache payloads stay scalar).
  - `RepositoryPage(array $items, bool $hasMore)`, `const PAGE_SIZE = 20`, `static empty(): self`, `static fromEntries(array $all, ?string $search, int $page): self` (case-insensitive substring filter on `fullName`, then 20-per-page slice).
  - trait `GuardsRepositoryListing`: `protected function guardListingResponse(\Illuminate\Http\Client\Response $response, string $label): void`, `protected function listingUnavailable(string $label): GitAccessTemporarilyUnavailableException`, `protected function nextLinkUrl(?string $linkHeader): ?string`.
  - `GitRepositoryListingRejectedException extends RuntimeException` — the provider rejected the token/scope (401/403 that is not a rate limit).
  - config keys: `audit.repo_picker.cache_seconds` (300), `.max_pages` (5), `.rate_limit_per_minute` (60), `.max_search_length` (100).
- Consumes: `App\Exceptions\GitAccessTemporarilyUnavailableException` (exists; extends `RuntimeException`, plain message).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/GitProviders/RepositoryPageTest.php`:

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Services\GitProviders\RepositoryEntry;
use App\Services\GitProviders\RepositoryPage;
use Tests\TestCase;

class RepositoryPageTest extends TestCase
{
    private function entries(int $count): array
    {
        return array_map(
            fn (int $i): RepositoryEntry => new RepositoryEntry("acme/repo-{$i}", "https://github.com/acme/repo-{$i}", $i % 2 === 0, 'main', null),
            range(1, $count),
        );
    }

    public function test_slices_twenty_per_page_and_reports_more(): void
    {
        $page1 = RepositoryPage::fromEntries($this->entries(45), null, 1);
        $page3 = RepositoryPage::fromEntries($this->entries(45), null, 3);

        $this->assertCount(20, $page1->items);
        $this->assertTrue($page1->hasMore);
        $this->assertSame('acme/repo-1', $page1->items[0]->fullName);
        $this->assertCount(5, $page3->items);
        $this->assertFalse($page3->hasMore);
    }

    public function test_exactly_a_full_last_page_has_no_more(): void
    {
        $page = RepositoryPage::fromEntries($this->entries(40), null, 2);

        $this->assertCount(20, $page->items);
        $this->assertFalse($page->hasMore);
    }

    public function test_search_is_a_case_insensitive_substring_on_the_full_name(): void
    {
        $all = [
            new RepositoryEntry('Acme/Billing-API', 'https://github.com/Acme/Billing-API', true, 'main', null),
            new RepositoryEntry('acme/website', 'https://github.com/acme/website', false, null, null),
        ];

        $page = RepositoryPage::fromEntries($all, '  BILLING ', 1);

        $this->assertCount(1, $page->items);
        $this->assertSame('Acme/Billing-API', $page->items[0]->fullName);
    }

    public function test_no_match_and_out_of_range_pages_are_empty_not_errors(): void
    {
        $this->assertSame([], RepositoryPage::fromEntries($this->entries(3), 'zzz', 1)->items);
        $this->assertSame([], RepositoryPage::fromEntries($this->entries(3), null, 9)->items);
        $this->assertSame('acme/repo-1', RepositoryPage::fromEntries($this->entries(3), null, 0)->items[0]->fullName); // page 0 clamps to page 1
        $this->assertSame([], RepositoryPage::empty()->items);
        $this->assertFalse(RepositoryPage::empty()->hasMore);
    }

    public function test_entry_round_trips_through_an_array_with_missing_optional_fields(): void
    {
        $entry = RepositoryEntry::fromArray(['fullName' => 'a/b', 'url' => 'https://github.com/a/b', 'private' => true]);

        $this->assertSame('a/b', $entry->fullName);
        $this->assertTrue($entry->private);
        $this->assertNull($entry->defaultBranch);
        $this->assertNull($entry->updatedAt);
        $this->assertEquals($entry, RepositoryEntry::fromArray($entry->toArray()));
    }
}
```

`tests/Feature/Services/GitProviders/GuardsRepositoryListingTest.php`:

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use App\Services\GitProviders\GuardsRepositoryListing;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuardsRepositoryListingTest extends TestCase
{
    private function guard(): object
    {
        return new class
        {
            use GuardsRepositoryListing;

            public function check($response): void
            {
                $this->guardListingResponse($response, 'GitHub');
            }

            public function next(?string $header): ?string
            {
                return $this->nextLinkUrl($header);
            }
        };
    }

    public function test_a_successful_response_passes(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->guard()->check(Http::get('https://x.test'));

        $this->addToAssertionCount(1);
    }

    public function test_401_and_an_ordinary_403_mean_the_token_was_rejected(): void
    {
        foreach ([401, 403] as $status) {
            Http::fake(['*' => Http::response([], $status)]);

            try {
                $this->guard()->check(Http::get('https://x.test'));
                $this->fail("{$status} should be rejected");
            } catch (GitRepositoryListingRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_rate_limited_403_is_transient_not_a_rejection(): void
    {
        Http::fake(['*' => Http::response([], 403, ['X-RateLimit-Remaining' => '0'])]);

        $this->expectException(GitAccessTemporarilyUnavailableException::class);

        $this->guard()->check(Http::get('https://x.test'));
    }

    public function test_429_5xx_and_unknown_statuses_are_transient(): void
    {
        foreach ([429, 500, 503, 404, 422] as $status) {
            Http::fake(['*' => Http::response([], $status)]);

            try {
                $this->guard()->check(Http::get('https://x.test'));
                $this->fail("{$status} should be transient");
            } catch (GitAccessTemporarilyUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_next_link_is_parsed_from_a_github_link_header(): void
    {
        $header = '<https://api.github.com/user/repos?page=2>; rel="next", <https://api.github.com/user/repos?page=9>; rel="last"';

        $this->assertSame('https://api.github.com/user/repos?page=2', $this->guard()->next($header));
        $this->assertNull($this->guard()->next('<https://api.github.com/user/repos?page=9>; rel="last"'));
        $this->assertNull($this->guard()->next(null));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryPageTest|GuardsRepositoryListingTest'`
Expected: FAIL — classes `RepositoryPage`, `RepositoryEntry`, trait and exception not found.

- [ ] **Step 3: Implement**

`app/Services/GitProviders/RepositoryEntry.php`:

```php
<?php

namespace App\Services\GitProviders;

/**
 * One repository in a connected account's listing. Scalar-only so it can sit in
 * the cache as a plain array; $url is the canonical https page URL, the shape
 * GitRepoAccessResolver accepts for a launch.
 */
final class RepositoryEntry
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $url,
        public readonly bool $private,
        public readonly ?string $defaultBranch,
        public readonly ?string $updatedAt,
    ) {}

    /** @return array{fullName: string, url: string, private: bool, defaultBranch: ?string, updatedAt: ?string} */
    public function toArray(): array
    {
        return [
            'fullName' => $this->fullName,
            'url' => $this->url,
            'private' => $this->private,
            'defaultBranch' => $this->defaultBranch,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['fullName'],
            (string) $row['url'],
            (bool) ($row['private'] ?? false),
            isset($row['defaultBranch']) ? (string) $row['defaultBranch'] : null,
            isset($row['updatedAt']) ? (string) $row['updatedAt'] : null,
        );
    }
}
```

`app/Services/GitProviders/RepositoryPage.php`:

```php
<?php

namespace App\Services\GitProviders;

/** One page of a connected account's repositories. */
final class RepositoryPage
{
    public const PAGE_SIZE = 20;

    /** @param list<RepositoryEntry> $items */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
    ) {}

    public static function empty(): self
    {
        return new self([], false);
    }

    /**
     * Filter a full listing by a case-insensitive substring of the full name, then
     * cut the requested page. Providers with no server-side search build their
     * page from this.
     *
     * @param  list<RepositoryEntry>  $all
     */
    public static function fromEntries(array $all, ?string $search, int $page): self
    {
        $term = mb_strtolower(trim((string) $search));

        $matching = $term === ''
            ? $all
            : array_values(array_filter($all, fn (RepositoryEntry $e): bool => str_contains(mb_strtolower($e->fullName), $term)));

        $page = max(1, $page);

        return new self(
            array_slice($matching, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            count($matching) > $page * self::PAGE_SIZE,
        );
    }
}
```

`app/Exceptions/GitRepositoryListingRejectedException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider refused to list repositories for a connection: the token was
 * revoked, or it lacks a scope the listing needs. The connection may still clone;
 * the tenant should reconnect to enable the picker.
 */
class GitRepositoryListingRejectedException extends RuntimeException {}
```

`app/Services/GitProviders/GuardsRepositoryListing.php`:

```php
<?php

namespace App\Services\GitProviders;

use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Exceptions\GitRepositoryListingRejectedException;
use Illuminate\Http\Client\Response;

/**
 * Shared status handling for the providers' repository-listing calls. Messages are
 * fixed text: nothing from the request or response (never the token) is echoed.
 */
trait GuardsRepositoryListing
{
    /**
     * 401 and an ordinary 403 mean the token (or its scope) was rejected. A 403 that
     * is a rate limit, 429, 5xx and anything unexpected are transient: try again.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    protected function guardListingResponse(Response $response, string $label): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $rateLimited = $response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== '';

        if ($status === 401 || ($status === 403 && ! $rateLimited)) {
            throw new GitRepositoryListingRejectedException("{$label} rejected the repository listing");
        }

        throw $this->listingUnavailable($label);
    }

    protected function listingUnavailable(string $label): GitAccessTemporarilyUnavailableException
    {
        return new GitAccessTemporarilyUnavailableException("{$label} repository listing is temporarily unavailable");
    }

    /** The `rel="next"` URL of a GitHub-style Link header, or null on the last page. */
    protected function nextLinkUrl(?string $linkHeader): ?string
    {
        if ($linkHeader !== null && preg_match('/<([^>]+)>\s*;\s*rel="next"/', $linkHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
```

`config/audit.php` — after the `'git_refresh_lock_wait' => 25,` line add:

```php
    // The dashboard repository picker: provider listings are cached per connection,
    // GitHub/Bitbucket are walked at most `max_pages` pages (100 repos each) before
    // being filtered locally, and each user may make `rate_limit_per_minute` listing
    // calls a minute (every picker action costs about two: the action, then its render).
    'repo_picker' => [
        'cache_seconds' => 300,
        'max_pages' => 5,
        'rate_limit_per_minute' => 60,
        'max_search_length' => 100,
    ],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryPageTest|GuardsRepositoryListingTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run `vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`.

```bash
git add app/Services/GitProviders/RepositoryEntry.php app/Services/GitProviders/RepositoryPage.php app/Services/GitProviders/GuardsRepositoryListing.php app/Exceptions/GitRepositoryListingRejectedException.php config/audit.php tests/Feature/Services/GitProviders/RepositoryPageTest.php tests/Feature/Services/GitProviders/GuardsRepositoryListingTest.php
git commit -m "feat(git): repository listing value objects and shared response guard"
```
(append the two attribution lines to the message).

---

### Task 2: GitHub `listRepositories()`

**Files:**
- Modify: `app/Services/GitProviders/GitHubProvider.php`
- Test: `tests/Feature/Services/GitProviders/GitHubProviderTest.php` (append tests)

**Interfaces:**
- Consumes: `RepositoryEntry`, `RepositoryPage`, `GuardsRepositoryListing`, `GitRepositoryListingRejectedException`, `GitAccessTemporarilyUnavailableException`, config `audit.repo_picker.*` (Task 1).
- Produces: `GitHubProvider::listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage` (public; added to the `GitProvider` interface in Task 4).

- [ ] **Step 1: Write the failing tests** (append to `GitHubProviderTest`; add `use App\Exceptions\GitAccessTemporarilyUnavailableException; use App\Exceptions\GitRepositoryListingRejectedException; use Illuminate\Http\Client\ConnectionException;` imports)

```php
    private function githubRepo(string $fullName, bool $private = false, ?string $branch = 'main'): array
    {
        return [
            'full_name' => $fullName,
            'html_url' => "https://github.com/{$fullName}",
            'private' => $private,
            'default_branch' => $branch,
            'pushed_at' => '2026-09-01T10:00:00Z',
        ];
    }

    public function test_lists_repositories_newest_push_first_with_the_connections_token(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9101, 'access_token' => 'gho_list_token']);
        Http::fake(['api.github.com/user/repos*' => Http::response([
            $this->githubRepo('acme/api', true),
            $this->githubRepo('acme/web', false, null),
        ])]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/api', 'acme/web'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://github.com/acme/api', $page->items[0]->url);
        $this->assertTrue($page->items[0]->private);
        $this->assertNull($page->items[1]->defaultBranch);
        $this->assertSame('2026-09-01T10:00:00Z', $page->items[0]->updatedAt);
        $this->assertFalse($page->hasMore);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer gho_list_token')
            && str_contains($request->url(), 'sort=pushed')
            && str_contains($request->url(), 'per_page=100')
            && str_contains($request->url(), 'affiliation=owner%2Ccollaborator%2Corganization_member'));
    }

    public function test_follows_the_next_link_up_to_the_page_cap(): void
    {
        config(['audit.repo_picker.max_pages' => 2]);
        $connection = TenantGitConnection::factory()->make(['id' => 9102]);
        Http::fake([
            'api.github.com/user/repos?page=2*' => Http::response([$this->githubRepo('acme/two')], 200, ['Link' => '<https://api.github.com/user/repos?page=3>; rel="next"']),
            'api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/one')], 200, ['Link' => '<https://api.github.com/user/repos?page=2>; rel="next"']),
        ]);

        $page = (new GitHubProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['acme/one', 'acme/two'], array_map(fn ($e) => $e->fullName, $page->items));
        Http::assertSentCount(2); // page 3 exists but the cap stops the walk
    }

    public function test_search_filters_locally_and_paginates_twenty_at_a_time(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9103]);
        $repos = array_map(fn ($i) => $this->githubRepo("acme/svc-{$i}"), range(1, 25));
        $repos[] = $this->githubRepo('acme/website');
        Http::fake(['api.github.com/user/repos*' => Http::response($repos)]);

        $provider = new GitHubProvider;
        $first = $provider->listRepositories($connection, 'SVC', 1);
        $second = $provider->listRepositories($connection, 'svc', 2);
        $none = $provider->listRepositories($connection, 'nothing-like-this', 1);

        $this->assertCount(20, $first->items);
        $this->assertTrue($first->hasMore);
        $this->assertCount(5, $second->items);
        $this->assertSame([], $none->items);
    }

    public function test_the_listing_is_cached_per_connection_and_not_shared_between_connections(): void
    {
        $a = TenantGitConnection::factory()->make(['id' => 9104, 'access_token' => 'token-a']);
        $b = TenantGitConnection::factory()->make(['id' => 9105, 'access_token' => 'token-b']);
        Http::fake(['api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/api')])]);

        $provider = new GitHubProvider;
        $provider->listRepositories($a, null, 1);
        $provider->listRepositories($a, 'api', 1); // same cached listing, different filter
        Http::assertSentCount(1);

        $provider->listRepositories($b, null, 1);
        Http::assertSentCount(2);
    }

    public function test_a_401_or_plain_403_is_a_rejection_and_is_not_cached(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9106]);
        Http::fake(['api.github.com/user/repos*' => Http::response(['message' => 'Bad credentials'], 401)]);

        try {
            (new GitHubProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a rejection');
        } catch (GitRepositoryListingRejectedException $e) {
            $this->assertStringNotContainsString($connection->access_token, $e->getMessage());
        }

        Http::fake(['api.github.com/user/repos*' => Http::response([$this->githubRepo('acme/api')])]);
        $this->assertCount(1, (new GitHubProvider)->listRepositories($connection, null, 1)->items);
    }

    public function test_rate_limits_5xx_and_network_failures_are_transient(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9107]);

        foreach ([
            Http::response([], 403, ['X-RateLimit-Remaining' => '0']),
            Http::response([], 502),
        ] as $response) {
            Http::fake(['api.github.com/user/repos*' => $response]);

            try {
                (new GitHubProvider)->listRepositories($connection, null, 1);
                $this->fail('expected a transient failure');
            } catch (GitAccessTemporarilyUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::fake(['api.github.com/user/repos*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new GitHubProvider)->listRepositories($connection, null, 1);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=GitHubProviderTest`
Expected: FAIL — `listRepositories` is undefined.

- [ ] **Step 3: Implement** in `GitHubProvider` (add `use GuardsRepositoryListing;` inside the class; add imports for `Illuminate\Http\Client\ConnectionException`, `App\Services\GitProviders` types are same namespace)

```php
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
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            "github_repos:{$connection->id}",
            now()->addSeconds((int) config('audit.repo_picker.cache_seconds')),
            fn (): array => $this->fetchRepositories($connection),
        );

        return RepositoryPage::fromEntries(array_map(RepositoryEntry::fromArray(...), $rows), $search, $page);
    }

    /** @return list<array<string, mixed>> */
    private function fetchRepositories(TenantGitConnection $connection): array
    {
        $rows = [];
        $url = 'https://api.github.com/user/repos';
        $query = [
            'affiliation' => 'owner,collaborator,organization_member',
            'sort' => 'pushed',
            'direction' => 'desc',
            'per_page' => 100,
        ];

        for ($fetched = 0; $fetched < (int) config('audit.repo_picker.max_pages'); $fetched++) {
            try {
                $response = Http::timeout(10)->connectTimeout(5)
                    ->withToken($connection->access_token)
                    ->get($url, $query);
            } catch (ConnectionException) {
                throw $this->listingUnavailable('GitHub');
            }

            $this->guardListingResponse($response, 'GitHub');

            foreach ($response->json() ?? [] as $repo) {
                if (! isset($repo['full_name'], $repo['html_url'])) {
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

            if ($next === null) {
                break;
            }

            $url = $next;
            $query = []; // the next link already carries its own query string
        }

        return $rows;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=GitHubProviderTest`
Expected: PASS (existing branch/credential tests still green).

- [ ] **Step 5: Gates and commit**

`vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`.

```bash
git add app/Services/GitProviders/GitHubProvider.php tests/Feature/Services/GitProviders/GitHubProviderTest.php
git commit -m "feat(git): list a connected GitHub account's repositories"
```
(append the attribution lines).

---

### Task 3: GitLab `listRepositories()`

**Files:**
- Modify: `app/Services/GitProviders/GitLabProvider.php`
- Test: `tests/Feature/Services/GitProviders/GitLabProviderTest.php` (append tests)

**Interfaces:**
- Consumes: as Task 2.
- Produces: `GitLabProvider::listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage` (public; on the interface in Task 4). GitLab searches and paginates server-side, so the cache is per connection + search + page.

- [ ] **Step 1: Write the failing tests** (append; add the same exception/`ConnectionException` imports)

```php
    private function gitlabProject(string $path, string $visibility = 'private', ?string $branch = 'main'): array
    {
        return [
            'path_with_namespace' => $path,
            'web_url' => "https://gitlab.com/{$path}",
            'visibility' => $visibility,
            'default_branch' => $branch,
            'last_activity_at' => '2026-09-02T08:30:00.000Z',
        ];
    }

    public function test_lists_projects_by_last_activity_with_server_side_search_and_paging(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9201, 'access_token' => 'glpat_list']);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response(
            [$this->gitlabProject('acme/team/app'), $this->gitlabProject('acme/site', 'public', null)],
            200,
            ['X-Next-Page' => '3'],
        )]);

        $page = (new GitLabProvider)->listRepositories($connection, ' app ', 2);

        $this->assertSame(['acme/team/app', 'acme/site'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://gitlab.com/acme/team/app', $page->items[0]->url);
        $this->assertTrue($page->items[0]->private);
        $this->assertFalse($page->items[1]->private); // public visibility
        $this->assertNull($page->items[1]->defaultBranch);
        $this->assertTrue($page->hasMore);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer glpat_list')
            && str_contains($request->url(), 'membership=true')
            && str_contains($request->url(), 'archived=false')
            && str_contains($request->url(), 'order_by=last_activity_at')
            && str_contains($request->url(), 'per_page=20')
            && str_contains($request->url(), 'page=2')
            && str_contains($request->url(), 'search=app'));
    }

    public function test_an_empty_next_page_header_means_no_more_and_no_search_param_is_sent_when_blank(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9202]);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([$this->gitlabProject('acme/app')], 200, ['X-Next-Page' => ''])]);

        $page = (new GitLabProvider)->listRepositories($connection, '   ', 1);

        $this->assertFalse($page->hasMore);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'search='));
    }

    public function test_pages_are_cached_per_connection_search_and_page(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9203]);
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([$this->gitlabProject('acme/app')])]);

        $provider = new GitLabProvider;
        $provider->listRepositories($connection, 'app', 1);
        $provider->listRepositories($connection, 'APP ', 1); // same normalised search
        Http::assertSentCount(1);

        $provider->listRepositories($connection, 'app', 2);
        Http::assertSentCount(2);
    }

    public function test_rejections_and_transient_failures_are_distinguished(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9204]);

        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([], 403)]);
        try {
            (new GitLabProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a rejection');
        } catch (GitRepositoryListingRejectedException) {
            $this->addToAssertionCount(1);
        }

        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([], 503)]);
        try {
            (new GitLabProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a transient failure');
        } catch (GitAccessTemporarilyUnavailableException) {
            $this->addToAssertionCount(1);
        }

        Http::fake(['gitlab.com/api/v4/projects*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new GitLabProvider)->listRepositories($connection, null, 1);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=GitLabProviderTest`
Expected: FAIL — `listRepositories` is undefined.

- [ ] **Step 3: Implement** in `GitLabProvider` (add `use GuardsRepositoryListing;` next to the existing `use InterpretsTokenRefreshResponses;`; import `ConnectionException`)

```php
    /**
     * One page of the token's projects, most recently active first. GitLab searches and
     * paginates server-side, so each (search, page) is cached on its own.
     *
     * @throws GitRepositoryListingRejectedException
     * @throws GitAccessTemporarilyUnavailableException
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage
    {
        $term = mb_strtolower(trim((string) $search));
        $page = max(1, $page);

        /** @var array{items: list<array<string, mixed>>, has_more: bool} $cached */
        $cached = Cache::remember(
            "gitlab_repos:{$connection->id}:".md5($term).":{$page}",
            now()->addSeconds((int) config('audit.repo_picker.cache_seconds')),
            fn (): array => $this->fetchRepositoryPage($connection, $term, $page),
        );

        return new RepositoryPage(array_map(RepositoryEntry::fromArray(...), $cached['items']), $cached['has_more']);
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=GitLabProviderTest`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

`vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`.

```bash
git add app/Services/GitProviders/GitLabProvider.php tests/Feature/Services/GitProviders/GitLabProviderTest.php
git commit -m "feat(git): list a connected GitLab account's projects"
```
(append the attribution lines).

---

### Task 4: Bitbucket `listRepositories()`, the `account` scope, and the interface method

**Files:**
- Modify: `app/Services/GitProviders/BitbucketProvider.php`
- Modify: `app/Services/GitProviders/GitProvider.php` (add the interface method)
- Test: `tests/Feature/Services/GitProviders/BitbucketProviderTest.php` (update `test_identity`, append tests)

**Interfaces:**
- Consumes: as Task 2.
- Produces: `BitbucketProvider::listRepositories(...)`; `GitProvider::listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage` on the interface (all three providers now satisfy it); `BitbucketProvider::authorizationScopes()` now `['repository', 'account']`.

- [ ] **Step 1: Check the scope separator (no code yet)**

Run: `docker compose exec -T laravel.test sh -c "grep -rn 'scopeSeparator\|scopes' vendor/socialiteproviders/bitbucket/ 2>/dev/null | head; grep -n bitbucket -A8 config/services.php"`
Expected: confirms how the Bitbucket Socialite driver joins scopes (Socialite's default is a comma; Bitbucket wants space-separated). If the driver joins with a comma, note it in the report and check `git log`/tests for how the existing single scope `repository` worked — Socialite `->scopes(['repository','account'])` must produce a valid `scope=repository account` value; if the driver does not set `$scopeSeparator = ' '`, set it via `Socialite::driver('bitbucket')->scopes(...)` only after confirming in a test of `OAuthController` redirect URL (do not modify `OAuthController.php`; if a fix is needed, report NEEDS_CONTEXT).

- [ ] **Step 2: Write the failing tests**

Update `test_identity`: `$this->assertSame(['repository', 'account'], $provider->authorizationScopes());`. Append (add imports for the two exceptions and `ConnectionException`):

```php
    private function bitbucketRepo(string $fullName, bool $private = true, ?string $branch = 'main', string $updated = '2026-09-03T09:00:00.000000+00:00'): array
    {
        return [
            'full_name' => $fullName,
            'is_private' => $private,
            'mainbranch' => $branch === null ? null : ['name' => $branch],
            'updated_on' => $updated,
        ];
    }

    private function fakeBitbucket(array $workspaces, array $reposBySlug): void
    {
        Http::fake(array_merge(
            ['api.bitbucket.org/2.0/workspaces*' => Http::response(['values' => array_map(fn ($s) => ['slug' => $s], $workspaces)])],
            collect($reposBySlug)->mapWithKeys(fn ($repos, $slug) => ["api.bitbucket.org/2.0/repositories/{$slug}*" => Http::response(['values' => $repos])])->all(),
        ));
    }

    public function test_lists_repositories_across_the_members_workspaces_newest_first(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9301, 'access_token' => 'bb_list_token']);
        $this->fakeBitbucket(['acme', 'side'], [
            'acme' => [$this->bitbucketRepo('acme/api', true, 'main', '2026-09-01T00:00:00.000000+00:00')],
            'side' => [$this->bitbucketRepo('side/blog', false, null, '2026-09-05T00:00:00.000000+00:00')],
        ]);

        $page = (new BitbucketProvider)->listRepositories($connection, null, 1);

        $this->assertSame(['side/blog', 'acme/api'], array_map(fn ($e) => $e->fullName, $page->items));
        $this->assertSame('https://bitbucket.org/side/blog', $page->items[0]->url);
        $this->assertFalse($page->items[0]->private);
        $this->assertNull($page->items[0]->defaultBranch);
        $this->assertSame('main', $page->items[1]->defaultBranch);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bb_list_token')
            && str_contains($request->url(), 'role=member'));
    }

    public function test_search_filters_locally_and_the_listing_is_cached(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9302]);
        $this->fakeBitbucket(['acme'], ['acme' => [$this->bitbucketRepo('acme/api'), $this->bitbucketRepo('acme/web')]]);

        $provider = new BitbucketProvider;
        $hit = $provider->listRepositories($connection, 'WEB', 1);
        $all = $provider->listRepositories($connection, null, 1);

        $this->assertSame(['acme/web'], array_map(fn ($e) => $e->fullName, $hit->items));
        $this->assertCount(2, $all->items);
        Http::assertSentCount(2); // one workspaces call + one repositories call, then cached
    }

    public function test_a_member_with_no_workspaces_gets_an_empty_page(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9303]);
        $this->fakeBitbucket([], []);

        $this->assertSame([], (new BitbucketProvider)->listRepositories($connection, null, 1)->items);
    }

    public function test_a_403_on_the_workspace_list_means_the_account_scope_is_missing(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9304]);
        Http::fake(['api.bitbucket.org/2.0/workspaces*' => Http::response(['error' => ['message' => 'Your credentials lack one or more required privilege scopes.']], 403)]);

        $this->expectException(GitRepositoryListingRejectedException::class);

        (new BitbucketProvider)->listRepositories($connection, null, 1);
    }

    public function test_transient_failures_are_distinguished_from_rejections(): void
    {
        $connection = TenantGitConnection::factory()->make(['id' => 9305]);

        Http::fake(['api.bitbucket.org/2.0/workspaces*' => Http::response([], 500)]);
        try {
            (new BitbucketProvider)->listRepositories($connection, null, 1);
            $this->fail('expected a transient failure');
        } catch (GitAccessTemporarilyUnavailableException) {
            $this->addToAssertionCount(1);
        }

        Http::fake(['api.bitbucket.org/2.0/workspaces*' => fn () => throw new ConnectionException('timeout')]);
        $this->expectException(GitAccessTemporarilyUnavailableException::class);
        (new BitbucketProvider)->listRepositories($connection, null, 1);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=BitbucketProviderTest`
Expected: FAIL (`listRepositories` undefined; identity scopes differ).

- [ ] **Step 4: Implement**

`BitbucketProvider`: add `use GuardsRepositoryListing;` and imports (`ConnectionException`); change scopes with a docblock:

```php
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
```

and:

```php
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
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            "bitbucket_repos:{$connection->id}",
            now()->addSeconds((int) config('audit.repo_picker.cache_seconds')),
            fn (): array => $this->fetchRepositories($connection),
        );

        return RepositoryPage::fromEntries(array_map(RepositoryEntry::fromArray(...), $rows), $search, $page);
    }

    private const MAX_WORKSPACES = 5;

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
    private function get(TenantGitConnection $connection, string $url, array $query): \Illuminate\Http\Client\Response
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
```

(Move `private const MAX_WORKSPACES` to the top of the class body with the other constants/properties, following the file's ordering.)

`GitProvider` interface — add, with the docblock:

```php
    /**
     * One page (RepositoryPage::PAGE_SIZE) of the repositories the connection's token can
     * see, optionally narrowed by a name search. Listings are cached briefly per connection.
     *
     * @throws GitRepositoryListingRejectedException when the provider refuses the listing
     *                                               (revoked token, missing scope): reconnect
     * @throws GitAccessTemporarilyUnavailableException on a transient failure (network,
     *                                                  provider 5xx, rate limit)
     */
    public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage;
```

(with `use App\Exceptions\GitRepositoryListingRejectedException;` and `use App\Exceptions\GitAccessTemporarilyUnavailableException;` imports.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='GitHubProviderTest|GitLabProviderTest|BitbucketProviderTest|GitConnectionServiceTest|GitConnectionsPageTest'`
Expected: PASS. If any test double implements `GitProvider` elsewhere (`grep -rn "implements GitProvider" app tests`), add the method there too.

- [ ] **Step 6: Gates and commit**

`vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`.

```bash
git add app/Services/GitProviders/BitbucketProvider.php app/Services/GitProviders/GitProvider.php tests/Feature/Services/GitProviders/BitbucketProviderTest.php
git commit -m "feat(git): list a connected Bitbucket account's repositories"
```
(append the attribution lines).

---

### Task 5: `RepositoryPicker` service and a provider-keyed connection lookup

**Files:**
- Create: `app/Services/GitProviders/RepositoryPickerState.php` (enum)
- Create: `app/Services/GitProviders/RepositoryPickerResult.php`
- Create: `app/Services/GitProviders/RepositoryPicker.php`
- Modify: `app/Services/GitProviders/GitRepoAccessResolver.php` (add `connectionForProvider()`)
- Test: `tests/Feature/Services/GitProviders/RepositoryPickerTest.php`, plus one test in the existing resolver test file (`grep -rln "GitRepoAccessResolver" tests/Feature/Services` to find it; add there)

**Interfaces:**
- Consumes: `GitProvider::listRepositories` (Tasks 2–4), `GitConnectionService::userMayManage(?Tenant, User): bool` (exists), `GitProviderResolver::KNOWN_PROVIDER_NAMES` / `forProviderName()` (exist), `GitRepoAccessResolver::freshen()` (private; the new method sits beside `connectionFor()`), config `audit.repo_picker.*`.
- Produces:
  - `GitRepoAccessResolver::connectionForProvider(string $providerName, Tenant $tenant): ?TenantGitConnection` — same refresh/`invalid_grant`/transient behavior as `connectionFor()`, keyed by provider name instead of URL.
  - `enum RepositoryPickerState: string { Ok; NoConnection; Reconnect; Unavailable; Throttled }`.
  - `RepositoryPickerResult(RepositoryPickerState $state, RepositoryPage $page)` with `static ok(RepositoryPage)`, `static of(RepositoryPickerState)` (empty page).
  - `RepositoryPicker::providersFor(?Tenant $tenant, User $user): array` — `list<string>` of connected provider names in the fixed order `github, gitlab, bitbucket`; `[]` unless the user may manage connections.
  - `RepositoryPicker::list(?Tenant $tenant, User $user, string $providerName, ?string $search, int $page): RepositoryPickerResult` — throws `Illuminate\Auth\Access\AuthorizationException` when the user may not manage the workspace's connections.
  - `RepositoryPicker::isListed(?Tenant $tenant, User $user, string $providerName, ?string $search, int $page, string $url): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/GitProviders/RepositoryPickerTest.php`:

```php
<?php

namespace Tests\Feature\Services\GitProviders;

use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use App\Services\GitProviders\RepositoryPicker;
use App\Services\GitProviders\RepositoryPickerState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\FeatureTest;

class RepositoryPickerTest extends FeatureTest
{
    private function member(bool $mayManage = true): array
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, $mayManage ? [TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS] : []);

        return [$tenant, $user];
    }

    private function connect(Tenant $tenant, string $provider = 'github', array $attributes = []): TenantGitConnection
    {
        return TenantGitConnection::factory()->create(['tenant_id' => $tenant->id, 'provider' => $provider] + $attributes);
    }

    private function fakeGithub(array $names): void
    {
        Http::fake(['api.github.com/user/repos*' => Http::response(array_map(fn ($n) => [
            'full_name' => $n,
            'html_url' => "https://github.com/{$n}",
            'private' => true,
            'default_branch' => 'main',
            'pushed_at' => '2026-09-01T10:00:00Z',
        ], $names))]);
    }

    public function test_providers_are_the_connected_ones_in_fixed_order(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'bitbucket');
        $this->connect($tenant, 'github');
        $otherTenant = Tenant::factory()->create();
        $this->connect($otherTenant, 'gitlab');

        $this->assertSame(['github', 'bitbucket'], app(RepositoryPicker::class)->providersFor($tenant, $user));
    }

    public function test_a_member_without_the_settings_permission_gets_no_providers_and_cannot_list(): void
    {
        [$tenant, $user] = $this->member(mayManage: false);
        $this->connect($tenant);
        Http::fake();

        $this->assertSame([], app(RepositoryPicker::class)->providersFor($tenant, $user));

        try {
            app(RepositoryPicker::class)->list($tenant, $user, 'github', null, 1);
            $this->fail('expected an AuthorizationException');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_user_outside_the_workspace_cannot_list(): void
    {
        [$tenant] = $this->member();
        $this->connect($tenant);
        $stranger = User::factory()->create();
        Http::fake();

        $this->expectException(AuthorizationException::class);

        app(RepositoryPicker::class)->list($tenant, $stranger, 'github', null, 1);
    }

    public function test_lists_with_the_current_workspaces_connection_only(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'github', ['access_token' => 'token-mine']);
        $other = Tenant::factory()->create();
        $this->connect($other, 'github', ['access_token' => 'token-other']);
        $this->fakeGithub(['acme/api']);

        $result = app(RepositoryPicker::class)->list($tenant, $user, 'github', null, 1);

        $this->assertSame(RepositoryPickerState::Ok, $result->state);
        $this->assertSame('acme/api', $result->page->items[0]->fullName);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-mine'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-other'));
    }

    public function test_no_connection_and_an_unknown_provider_are_no_connection(): void
    {
        [$tenant, $user] = $this->member();
        Http::fake();

        $picker = app(RepositoryPicker::class);

        $this->assertSame(RepositoryPickerState::NoConnection, $picker->list($tenant, $user, 'gitlab', null, 1)->state);
        $this->assertSame(RepositoryPickerState::NoConnection, $picker->list($tenant, $user, 'sourcehut', null, 1)->state);
        Http::assertNothingSent();
    }

    public function test_a_rejected_token_is_reconnect_and_an_outage_is_unavailable(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $picker = app(RepositoryPicker::class);

        Http::fake(['api.github.com/user/repos*' => Http::response([], 401)]);
        $this->assertSame(RepositoryPickerState::Reconnect, $picker->list($tenant, $user, 'github', null, 1)->state);

        Http::fake(['api.github.com/user/repos*' => Http::response([], 503)]);
        $this->assertSame(RepositoryPickerState::Unavailable, $picker->list($tenant, $user, 'github', null, 1)->state);
    }

    public function test_a_transient_token_refresh_failure_is_unavailable(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'gitlab', ['expires_at' => now()->subMinute(), 'refresh_token' => 'r']);
        Http::fake(['gitlab.com/oauth/token' => Http::response([], 503)]);

        $this->assertSame(RepositoryPickerState::Unavailable, app(RepositoryPicker::class)->list($tenant, $user, 'gitlab', null, 1)->state);
    }

    public function test_search_is_trimmed_and_length_capped(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant, 'gitlab');
        Http::fake(['gitlab.com/api/v4/projects*' => Http::response([])]);

        app(RepositoryPicker::class)->list($tenant, $user, 'gitlab', '  '.str_repeat('a', 500).'  ', 1);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['search'] === str_repeat('a', 100);
        });
    }

    public function test_listing_is_rate_limited_per_user(): void
    {
        config(['audit.repo_picker.rate_limit_per_minute' => 2]);
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $this->fakeGithub(['acme/api']);
        RateLimiter::clear("repo-picker:{$user->id}");
        $picker = app(RepositoryPicker::class);

        $picker->list($tenant, $user, 'github', null, 1);
        $picker->list($tenant, $user, 'github', null, 1);
        $third = $picker->list($tenant, $user, 'github', null, 1);

        $this->assertSame(RepositoryPickerState::Throttled, $third->state);
        RateLimiter::clear("repo-picker:{$user->id}");
    }

    public function test_is_listed_only_for_urls_in_the_accounts_listing(): void
    {
        [$tenant, $user] = $this->member();
        $this->connect($tenant);
        $this->fakeGithub(['acme/api']);
        $picker = app(RepositoryPicker::class);

        $this->assertTrue($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/api'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/secret'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://gitlab.com/acme/api'));
        $this->assertFalse($picker->isListed($tenant, $user, 'github', null, 1, 'https://github.com/acme/api/'));
    }
}
```

Resolver test (add to the existing resolver test class, using its idioms):

```php
    public function test_connection_for_provider_returns_the_tenants_own_connection_and_refreshes_like_connection_for(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $mine = TenantGitConnection::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'github']);
        TenantGitConnection::factory()->create(['tenant_id' => $other->id, 'provider' => 'github']);

        $resolver = app(GitRepoAccessResolver::class);

        $this->assertSame($mine->id, $resolver->connectionForProvider('github', $tenant)?->id);
        $this->assertNull($resolver->connectionForProvider('gitlab', $tenant));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryPickerTest|connection_for_provider'`
Expected: FAIL — classes and method not found.

- [ ] **Step 3: Implement**

`GitRepoAccessResolver` — next to `connectionFor()`:

```php
    /**
     * connectionFor(), keyed by provider name rather than a repo URL: the repository picker
     * lists a provider's repos before any URL exists. Same refresh, `invalid_grant` deletion
     * and transient-failure behavior.
     *
     * @throws GitAccessTemporarilyUnavailableException
     */
    public function connectionForProvider(string $providerName, Tenant $tenant): ?TenantGitConnection
    {
        $provider = $this->providers->forProviderName($providerName);
        $connection = $this->storedConnection($provider, $tenant);

        return $connection === null ? null : $this->freshen($provider, $connection);
    }
```

`RepositoryPickerState.php`:

```php
<?php

namespace App\Services\GitProviders;

enum RepositoryPickerState: string
{
    case Ok = 'ok';
    case NoConnection = 'no_connection';
    /** The provider refused the listing: the token was revoked or lacks a scope. */
    case Reconnect = 'reconnect';
    /** Network, provider 5xx or rate limit: try again in a minute. */
    case Unavailable = 'unavailable';
    /** This user made too many listing calls in the last minute. */
    case Throttled = 'throttled';
}
```

`RepositoryPickerResult.php`:

```php
<?php

namespace App\Services\GitProviders;

final class RepositoryPickerResult
{
    public function __construct(
        public readonly RepositoryPickerState $state,
        public readonly RepositoryPage $page,
    ) {}

    public static function ok(RepositoryPage $page): self
    {
        return new self(RepositoryPickerState::Ok, $page);
    }

    public static function of(RepositoryPickerState $state): self
    {
        return new self($state, RepositoryPage::empty());
    }
}
```

`RepositoryPicker.php`:

```php
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
            $connection = $this->access->connectionForProvider($providerName, $tenant);

            if ($connection === null) {
                return RepositoryPickerResult::of(RepositoryPickerState::NoConnection);
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='RepositoryPickerTest|connection_for_provider'`
Expected: PASS. (The transient-refresh test relies on the GitLab provider's existing refresh call hitting `gitlab.com/oauth/token`; if the refresh failure path in the existing resolver tests uses a different fake, mirror it.)

- [ ] **Step 5: Gates and commit**

`vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`.

```bash
git add app/Services/GitProviders/RepositoryPickerState.php app/Services/GitProviders/RepositoryPickerResult.php app/Services/GitProviders/RepositoryPicker.php app/Services/GitProviders/GitRepoAccessResolver.php tests/Feature/Services/GitProviders/RepositoryPickerTest.php <the resolver test file you edited>
git commit -m "feat(git): permission-gated, rate-limited repository picker service"
```
(append the attribution lines).

---

### Task 6: The launch form — picker UI, page actions, and spec update

**Files:**
- Modify: `app/Filament/Dashboard/Pages/AuditReports.php`
- Modify: `resources/views/filament/dashboard/pages/audit-reports.blade.php` (the `<x-filament::section class="fp-launch">` repository block, lines ≈ 7–28)
- Modify: `docs/superpowers/specs/2026-09-29-repository-picker-design.md` (record the deviations)
- Test: `tests/Feature/Filament/Dashboard/AuditReportsRepositoryPickerTest.php`

**Interfaces:**
- Consumes: `RepositoryPicker`, `RepositoryPickerState`, `RepositoryPickerResult`, `RepositoryPage`, `RepositoryEntry` (Task 5/1), `GitConnections::getUrl()` (Filament page URL helper), the page's existing `repoUrl`, `branch`, `loadBranches()`, `launchAudit()`.
- Produces on `AuditReports`:
  - public props: `string $pickerMode = 'picker'` (`picker`|`url`), `?string $pickerProvider = null`, `string $repoSearch = ''`, `int $repoPage = 1`.
  - `pickerProviders(): array` (list of provider names; `[]` = no picker), `pickerResult(): ?RepositoryPickerResult`.
  - actions: `selectProvider(string $provider)`, `updatedRepoSearch()`, `nextRepositoryPage()`, `previousRepositoryPage()`, `chooseRepository(string $url)`, `clearRepository()`, `usePicker()`, `useUrlInput()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Filament/Dashboard/AuditReportsRepositoryPickerTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsRepositoryPickerTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRepositoryAccess();
    }

    /** @return array{0: User, 1: Tenant} a member with audit allowance who may manage git connections */
    private function manager(bool $mayManage = true): array
    {
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);

        if ($mayManage) {
            $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot
                ->givePermissionTo(TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS);
        }

        $this->actAsTenantUser($user, $tenant);
        RateLimiter::clear("repo-picker:{$user->id}");

        return [$user, $tenant];
    }

    private function connect(Tenant $tenant, string $provider = 'github'): TenantGitConnection
    {
        return TenantGitConnection::factory()->create(['tenant_id' => $tenant->id, 'provider' => $provider]);
    }

    private function fakeGithub(array $names = ['acme/api', 'acme/web']): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response(array_map(fn ($n) => [
                'full_name' => $n,
                'html_url' => "https://github.com/{$n}",
                'private' => true,
                'default_branch' => 'main',
                'pushed_at' => '2026-09-01T10:00:00Z',
            ], $names)),
            'api.github.com/repos/*/branches*' => Http::response([['name' => 'main'], ['name' => 'develop']]),
        ]);
    }

    public function test_without_a_connection_the_form_is_unchanged_and_offers_to_connect(): void
    {
        [$user, $tenant] = $this->manager();

        Livewire::test(AuditReports::class)
            ->assertSee('Repository URL')
            ->assertSee('Private repository?')
            ->assertSee('Connect an account')
            ->assertDontSee('Paste a URL instead');
    }

    public function test_a_member_without_the_permission_sees_no_picker_and_no_connect_link(): void
    {
        [$user, $tenant] = $this->manager(mayManage: false);
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->assertSee('Repository URL')
            ->assertDontSee('acme/api')
            ->assertDontSee('Paste a URL instead')
            ->assertDontSee('Connect an account');

        Http::assertNothingSent();
    }

    public function test_with_a_connection_the_picker_lists_repositories_and_the_url_box_is_a_fallback(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->assertSet('pickerProvider', 'github')
            ->assertSee('acme/api')
            ->assertSee('acme/web')
            ->assertSee('Paste a URL instead')
            ->assertDontSee('Private repository?')
            ->call('useUrlInput')
            ->assertSee('Private repository?')
            ->assertSee('Choose from a connected account')
            ->call('usePicker')
            ->assertSee('acme/api');
    }

    public function test_choosing_a_repository_sets_the_url_and_loads_its_branches(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->assertSet('repoUrl', 'https://github.com/acme/api')
            ->assertSet('branchesByRepo', ['https://github.com/acme/api' => ['main', 'develop']])
            ->assertSee('Repo default branch')
            ->call('clearRepository')
            ->assertSet('repoUrl', null)
            ->assertSet('branch', null);
    }

    public function test_a_url_that_is_not_in_the_accounts_listing_is_rejected(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/someone-else/secret')
            ->assertSet('repoUrl', null)
            ->call('chooseRepository', 'https://gitlab.com/acme/api')
            ->assertSet('repoUrl', null);
    }

    public function test_a_forged_provider_is_ignored_and_a_member_without_the_permission_gets_a_403(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('selectProvider', 'gitlab') // no gitlab connection
            ->assertSet('pickerProvider', 'github')
            ->call('selectProvider', 'not-a-provider')
            ->assertSet('pickerProvider', 'github');

        [$plain, $plainTenant] = $this->manager(mayManage: false);
        $this->connect($plainTenant);

        Livewire::test(AuditReports::class)
            ->set('pickerProvider', 'github') // client-forged public property
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->assertSet('repoUrl', null);
    }

    public function test_search_narrows_the_list_and_paging_walks_it(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub(array_merge(array_map(fn ($i) => "acme/svc-{$i}", range(1, 25)), ['acme/website']));

        Livewire::test(AuditReports::class)
            ->assertSee('acme/svc-1')
            ->assertDontSee('acme/svc-21')
            ->call('nextRepositoryPage')
            ->assertSet('repoPage', 2)
            ->assertSee('acme/svc-21')
            ->call('previousRepositoryPage')
            ->assertSet('repoPage', 1)
            ->set('repoSearch', 'web')
            ->assertSet('repoPage', 1)
            ->assertSee('acme/website')
            ->assertDontSee('acme/svc-1')
            ->set('repoSearch', 'no-such-repo')
            ->assertSee('No repositories found');
    }

    public function test_a_revoked_token_shows_reconnect_and_the_url_box_stays_reachable(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        Http::fake(['api.github.com/user/repos*' => Http::response([], 401)]);

        Livewire::test(AuditReports::class)
            ->assertSee('Reconnect your GitHub account')
            ->assertSee('Paste a URL instead')
            ->call('useUrlInput')
            ->assertSee('Repository URL');
    }

    public function test_an_outage_shows_try_again_and_the_url_box_stays_reachable(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        Http::fake(['api.github.com/user/repos*' => Http::response([], 503)]);

        Livewire::test(AuditReports::class)
            ->assertSee("Couldn't reach GitHub")
            ->assertSee('Paste a URL instead')
            ->call('useUrlInput')
            ->assertSee('Repository URL');
    }

    public function test_a_picked_repository_launches_exactly_like_a_pasted_url(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::test(AuditReports::class)
            ->call('chooseRepository', 'https://github.com/acme/api')
            ->call('launchAudit');

        $request = AuditRequest::where('user_id', $user->id)->sole();
        $this->assertSame('https://github.com/acme/api', $request->repo_url);
        $this->assertSame($tenant->id, $request->tenant_id);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_email_link_still_prefills_the_url_box(): void
    {
        [$user, $tenant] = $this->manager();
        $this->connect($tenant);
        $this->fakeGithub();

        Livewire::withQueryParams(['repo' => 'https://github.com/acme/api'])
            ->test(AuditReports::class)
            ->assertSet('repoUrl', 'https://github.com/acme/api')
            ->assertSet('pickerMode', 'url');
    }
}
```

Note: `$this->fakeRepositoryAccess()` (FeatureTest) mocks `RepositoryCloner::preflight` so `launchAudit` passes preflight. Provider listings are cached per connection id, and every `connect()` creates a new connection, so tests never share a cached listing.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=AuditReportsRepositoryPickerTest`
Expected: FAIL — properties/actions missing.

- [ ] **Step 3: Implement the page** (`AuditReports.php`; add imports `App\Services\GitProviders\GitConnectionService`? not needed; `App\Filament\Dashboard\Pages\GitConnections` is in the same namespace; add `App\Services\GitProviders\RepositoryPicker`, `App\Services\GitProviders\RepositoryPickerResult`, `App\Services\GitProviders\RepositoryPickerState`, `Illuminate\Auth\Access\AuthorizationException`)

Public properties (beside `$branch`):

```php
    /** 'picker' (choose from a connected account) or 'url' (paste one). */
    public string $pickerMode = 'picker';

    public ?string $pickerProvider = null;

    public string $repoSearch = '';

    public int $repoPage = 1;
```

In `mount()`, replace the `?repo=` block so it also switches to URL mode:

```php
        if (is_string($repo) && str_starts_with($repo, 'http')) {
            $this->repoUrl = $repo;
            $this->pickerMode = 'url';
        }

        $this->pickerProvider = $this->pickerProviders()[0] ?? null;
```

New methods (place after `loadBranches()`/its docblocked helper):

```php
    /**
     * The providers the repository picker can list from: the workspace's connections, and
     * only for a member who may manage them (see RepositoryPicker). Empty means no picker.
     *
     * @return list<string>
     */
    public function pickerProviders(): array
    {
        return app(RepositoryPicker::class)->providersFor(Filament::getTenant(), auth()->user());
    }

    public function pickerResult(): ?RepositoryPickerResult
    {
        if ($this->pickerMode !== 'picker' || $this->pickerProvider === null) {
            return null;
        }

        try {
            return app(RepositoryPicker::class)->list(Filament::getTenant(), auth()->user(), $this->pickerProvider, $this->repoSearch, $this->repoPage);
        } catch (AuthorizationException) {
            // The property is client-editable; a member who lost the permission mid-session
            // simply loses the picker.
            return null;
        }
    }

    public function selectProvider(string $provider): void
    {
        if (! in_array($provider, $this->pickerProviders(), true)) {
            return;
        }

        $this->pickerProvider = $provider;
        $this->repoSearch = '';
        $this->repoPage = 1;
    }

    public function updatedRepoSearch(): void
    {
        $this->repoSearch = mb_substr(trim($this->repoSearch), 0, (int) config('audit.repo_picker.max_search_length'));
        $this->repoPage = 1;
    }

    public function nextRepositoryPage(): void
    {
        $this->repoPage++;
    }

    public function previousRepositoryPage(): void
    {
        $this->repoPage = max(1, $this->repoPage - 1);
    }

    public function usePicker(): void
    {
        $this->pickerMode = 'picker';
    }

    public function useUrlInput(): void
    {
        $this->pickerMode = 'url';
    }

    public function clearRepository(): void
    {
        $this->repoUrl = null;
        $this->branch = null;
    }

    /**
     * $url arrives from the client, and so does pickerProvider/repoSearch/repoPage: none of
     * it is evidence the account can see the repo. It is accepted only when the account's own
     * listing (server-side, cached) contains it, so this cannot become a way to probe for
     * repositories.
     */
    public function chooseRepository(string $url): void
    {
        if ($this->pickerProvider === null) {
            return;
        }

        try {
            $listed = app(RepositoryPicker::class)->isListed(
                Filament::getTenant(),
                auth()->user(),
                $this->pickerProvider,
                $this->repoSearch,
                $this->repoPage,
                $url,
            );
        } catch (AuthorizationException) {
            return;
        }

        if (! $listed) {
            Notification::make()
                ->title(__('That repository is no longer in the list'))
                ->body(__('Search again, or paste its URL instead.'))
                ->warning()
                ->send();

            return;
        }

        $this->repoUrl = $url;
        $this->branch = null;
        $this->loadBranches($url);
    }
```

(`loadBranches()` needs no change: `userMayLookUpBranchesFor()` allows it because `$this->repoUrl` now equals `$url`.)

- [ ] **Step 4: Implement the view.** In `audit-reports.blade.php`, extend the `@php` block at the top:

```php
            $pickerProviders = $this->pickerProviders();
            $picker = $pickerProviders !== [] && $pickerMode === 'picker' ? $this->pickerResult() : null;
            $pickerLabels = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];
            $canManageConnections = \App\Filament\Dashboard\Pages\GitConnections::canAccess();
```

Replace the `<div class="min-w-0"> … </div>` repository block (label, URL input wrapper, and "Private repository?" `<details>`) with:

```blade
                <div class="min-w-0">
                    @if ($pickerProviders !== [] && $pickerMode === 'picker')
                        <span class="fp-mono-label">{{ __('Repository') }}</span>

                        @if ($repoUrl)
                            <div class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
                                <span class="fp-repo truncate font-medium text-gray-950 dark:text-white">{{ \App\Support\RepoName::short($repoUrl) }}</span>
                                <x-filament::link tag="button" wire:click="clearRepository" size="sm">{{ __('Choose another') }}</x-filament::link>
                            </div>
                        @else
                            @if (count($pickerProviders) > 1)
                                <div class="mt-2 flex gap-2" role="tablist" aria-label="{{ __('Git provider') }}">
                                    @foreach ($pickerProviders as $name)
                                        <x-filament::button size="sm" :color="$pickerProvider === $name ? 'primary' : 'gray'" wire:click="selectProvider('{{ $name }}')" role="tab" aria-selected="{{ $pickerProvider === $name ? 'true' : 'false' }}">
                                            {{ $pickerLabels[$name] }}
                                        </x-filament::button>
                                    @endforeach
                                </div>
                            @endif

                            <x-filament::input.wrapper class="mt-2">
                                <x-filament::input type="search" wire:model.live.debounce.400ms="repoSearch" placeholder="{{ __('Search your repositories') }}" maxlength="100" autocomplete="off" spellcheck="false" aria-label="{{ __('Search your repositories') }}" />
                            </x-filament::input.wrapper>

                            @if ($picker?->state === \App\Services\GitProviders\RepositoryPickerState::Ok)
                                @if ($picker->page->items === [])
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('No repositories found for this account.') }}
                                        @if ($pickerProvider === 'github')
                                            {{ __('Organization repositories appear only after the organization approves FlexPick on GitHub.') }}
                                        @endif
                                    </p>
                                @else
                                    <ul class="mt-2 divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10" role="listbox" aria-label="{{ __('Repositories') }}">
                                        @foreach ($picker->page->items as $entry)
                                            <li wire:key="repo-{{ $pickerProvider }}-{{ $entry->fullName }}">
                                                <button type="button" wire:click="chooseRepository({{ \Illuminate\Support\Js::from($entry->url) }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5" role="option">
                                                    <span class="truncate font-medium text-gray-950 dark:text-white">{{ $entry->fullName }}</span>
                                                    <span class="fp-mono-label shrink-0">{{ $entry->private ? __('private') : __('public') }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($repoPage > 1 || $picker->page->hasMore)
                                    <div class="mt-2 flex justify-between">
                                        <x-filament::link tag="button" wire:click="previousRepositoryPage" size="sm" :disabled="$repoPage <= 1">{{ __('Previous') }}</x-filament::link>
                                        @if ($picker->page->hasMore)
                                            <x-filament::link tag="button" wire:click="nextRepositoryPage" size="sm">{{ __('Next') }}</x-filament::link>
                                        @endif
                                    </div>
                                @endif
                            @elseif ($picker?->state === \App\Services\GitProviders\RepositoryPickerState::Reconnect)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('Reconnect your :provider account to list its repositories.', ['provider' => $pickerLabels[$pickerProvider]]) }}
                                    <a class="underline" href="{{ \App\Filament\Dashboard\Pages\GitConnections::getUrl() }}">{{ __('Git Connections') }}</a>
                                </p>
                            @elseif ($picker?->state === \App\Services\GitProviders\RepositoryPickerState::Unavailable)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __("Couldn't reach :provider just now. Try again in a minute, or paste a URL instead.", ['provider' => $pickerLabels[$pickerProvider]]) }}</p>
                            @elseif ($picker?->state === \App\Services\GitProviders\RepositoryPickerState::Throttled)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Too many lookups — wait a moment, or paste a URL instead.') }}</p>
                            @endif
                        @endif

                        <p class="mt-2 text-sm">
                            <x-filament::link tag="button" wire:click="useUrlInput" size="sm">{{ __('Paste a URL instead') }}</x-filament::link>
                        </p>
                    @else
                        <label class="fp-mono-label" for="audit-repo-url">{{ __('Repository URL') }}</label>
                        <x-filament::input.wrapper class="fp-launch-url mt-2">
                            <x-filament::input id="audit-repo-url" type="url" wire:model.live.blur="repoUrl" placeholder="https://github.com/you/repo" aria-describedby="audit-private-repo" autocomplete="off" spellcheck="false" />
                        </x-filament::input.wrapper>

                        <details id="audit-private-repo" class="fp-disclosure mt-2">
                            <summary>{{ __('Private repository?') }}</summary>
                            <p>
                                {{ __('Connect your GitHub, GitLab, or Bitbucket account from the Git Connections page, then paste the URL here — private repos need a connected account before they can be analyzed.') }}
                            </p>
                        </details>

                        @if ($pickerProviders !== [])
                            <p class="mt-2 text-sm">
                                <x-filament::link tag="button" wire:click="usePicker" size="sm">{{ __('Choose from a connected account') }}</x-filament::link>
                            </p>
                        @elseif ($canManageConnections)
                            <p class="mt-2 text-sm">
                                <a class="underline" href="{{ \App\Filament\Dashboard\Pages\GitConnections::getUrl() }}">{{ __('Connect an account') }}</a>
                                {{ __('to pick from your repositories.') }}
                            </p>
                        @endif
                    @endif
                </div>
```

The branch selector block below (`@if ($launchBranches !== null) … @endif`) stays exactly as is. Guard the `Reconnect`/`Unavailable` outputs so a failure keeps the URL box reachable: in those states the "Paste a URL instead" link (rendered below the states) is the way out — keep it outside the `@if ($repoUrl)` / `@else` so it is always present in picker mode.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='AuditReportsRepositoryPickerTest|AuditReportsPageTest|AuditReportsRenderTest|AuditReportsTierSelectionTest|AuditReportsPurchaseTest'`
Expected: PASS. Fix any existing test that asserted the always-visible URL input for a workspace with a connection and a permitted member (none should: the existing tests do not create connections for permitted members; if one does, set `pickerMode` to `url` or assert the picker markup accordingly and note it in the report).

- [ ] **Step 6: Update the spec with the deviations, then the full gates**

Edit `docs/superpowers/specs/2026-09-29-repository-picker-design.md`:
- In "Provider layer": `RepositoryEntry::$updatedAt` is `?string` (ISO-8601) not `CarbonInterface`; Bitbucket row now reads `GET /2.0/workspaces?role=member` then `GET /2.0/repositories/{workspace}?role=member&sort=-updated_on&pagelen=100` and needs the `account` scope in addition to `repository` (consumer needs *Account: Read*; existing connections reconnect to enable the picker); the global and `user/permissions` Bitbucket listing endpoints are deprecated and not used.
- GitHub search is settled as "walk up to 5 pages of 100, cache per connection, filter locally" (Open item 2 closed); rate-limit default 60/min (every picker action costs about two calls) (Open item 3 closed); scopes for GitHub/GitLab confirmed sufficient (Open item 1 closed for them, Bitbucket resolved as above).
- In "Form UI": paging is Previous/Next (20 per page), not "Load more".
- Change status to `Approved (implemented)`.

Then: `vendor/bin/pint`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and the full suite once: `php artisan test --compact`.

```bash
git add app/Filament/Dashboard/Pages/AuditReports.php resources/views/filament/dashboard/pages/audit-reports.blade.php tests/Feature/Filament/Dashboard/AuditReportsRepositoryPickerTest.php docs/superpowers/specs/2026-09-29-repository-picker-design.md
git commit -m "feat(audit): pick a repository from a connected git account on Run an audit"
```
(append the attribution lines).

---

## Self-review notes (recorded by the plan author)

- **Spec coverage:** provider `listRepositories` + value objects + cache (T1–T4); resolver entry point by provider + permission/own-connection/rate-limit/search-cap (T5); page state, actions, `chooseRepository` listing-membership check, form UI incl. provider switcher, empty/error states, URL fallback, "Connect an account" link, `?repo=` link (T6); tests per spec section; open items resolved and written back into the spec (T6 step 6).
- **Spec deviations (intentional, recorded in T6):** Previous/Next instead of "Load more"; `updatedAt` as ISO string; Bitbucket via workspace-scoped endpoints with the `account` scope.
- **Type consistency:** `RepositoryPage::fromEntries`, `RepositoryEntry::fromArray/toArray`, `RepositoryPickerResult::ok/of`, `RepositoryPicker::providersFor/list/isListed`, `GitRepoAccessResolver::connectionForProvider` are named identically in every task that uses them.
