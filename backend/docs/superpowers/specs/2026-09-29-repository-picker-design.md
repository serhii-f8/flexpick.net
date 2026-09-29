# Repository picker on "Run an audit" — design

**Date:** 2026-09-29
**Status:** Draft for review
**Builds on:** `2026-09-27-multi-provider-git-access-and-sizing-design.md` (which lists a
`listRepos()` picker as "enabled by this design but not required by it").

## Goal

On the dashboard **Run an audit** page, a member whose workspace has a connected GitHub,
GitLab or Bitbucket account can pick a repository from that account instead of pasting a URL.

**Success:** a member with a connected account opens the page, searches or picks a repo, sees
its branches, and launches an audit without pasting a URL. Charging, sizing and the audit
pipeline behave exactly as they do today.

## Decisions (agreed)

- **Layout:** picker first, URL as fallback. With at least one connection the form shows a
  provider switcher and a searchable repository dropdown, plus a "Paste a URL instead" link.
  With no connection the form looks as it does today (URL box, "Private repository?" hint),
  plus a "Connect an account" link.
- **Data:** live listing from the provider API with a short cache. No new table, no
  background sync, no stored copy of a customer's repo list.
- **Providers:** GitHub, GitLab, Bitbucket (cloud). Self-hosted instances stay out of scope.
- **Access:** the list is served only to members who pass the same tenant-settings permission
  that gates the Git Connections page, and only from the caller's own workspace connection.

## Non-goals

- Changing how an audit is charged, sized, queued or analyzed.
- Listing repos for public/unconnected accounts (they use the URL box).
- Persisting or syncing repo lists.
- Self-hosted GitLab / Bitbucket Server.
- Removing the free-text URL input.

## Architecture

### Provider layer

Add to `App\Services\GitProviders\GitProvider`:

```php
public function listRepositories(TenantGitConnection $connection, ?string $search, int $page): RepositoryPage;
```

New value objects under `App\Services\GitProviders\`:

- `RepositoryPage` — `list<RepositoryEntry> $items`, `bool $hasMore`.
- `RepositoryEntry` — `string $fullName`, `string $url` (canonical https clone-page URL, the
  shape `GitRepoAccessResolver::isCanonicalHttpsUrl()` accepts), `bool $private`,
  `?string $defaultBranch`, `?CarbonInterface $updatedAt`.

Per provider (endpoints to be confirmed against current provider docs in the plan; see
"Open items"):

| Provider | Endpoint | Scope already requested |
|---|---|---|
| GitHub | `GET /user/repos?affiliation=owner,collaborator,organization_member&sort=pushed&per_page=20&page=N`; search filtered server-side over the fetched pages, or the repository search API when a term is given | `repo` |
| GitLab | `GET /projects?membership=true&simple=true&order_by=last_activity_at&per_page=20&page=N&search=…` | `read_api` |
| Bitbucket | `GET /2.0/repositories?role=member&sort=-updated_on&pagelen=20&page=N&q=…` | `repository` |

- Results are cached per `connection id + search + page` for 5 minutes (same idiom as the
  existing `github_branches:*` cache key).
- The connection is obtained through `GitRepoAccessResolver` so token refresh, `invalid_grant`
  deletion and the transient-unavailable exception all behave as they already do. The picker
  needs a resolver entry point that returns a fresh connection for a *provider* (not a URL),
  reusing `storedConnection()` + `freshen()`.
- Errors: a transient failure raises the existing `GitAccessTemporarilyUnavailableException`;
  a rejected token (`invalid_grant` / 401 after refresh) yields the "reconnect" state; any other
  API failure returns an empty page and is logged without token material.

### Dashboard page (`AuditReports`)

New Livewire state and methods on the existing page:

- `pickerProvider` (`github|gitlab|bitbucket|null`), `repoSearch`, `repoPage`.
- `repositoryChoices()` computed/`loadRepositories()` action returning the current
  `RepositoryPage` for the selected provider.
- `chooseRepository(string $url)` sets `$this->repoUrl` after re-validating the URL is canonical
  and its provider matches `pickerProvider`; it then triggers the existing `loadBranches()`.
- `usingPicker` boolean toggled by "Paste a URL instead" / "Choose from a connected account".

**Security invariants**

1. Every picker method aborts 403 unless the user passes
   `GitConnectionService::userMayManage($tenant, $user)` (tenant-settings permission), checked
   server-side in each method, not only in the view.
2. The connection is always the *current workspace's* connection for `pickerProvider`. No
   client-supplied connection id, URL host or token is ever used to select it.
3. `chooseRepository()` never trusts the client-supplied URL as evidence of visibility:
   it accepts a URL only if it appears in the current listing result for the current
   provider/search/page (re-fetched or read from the cache), otherwise it rejects. This keeps
   the picker from becoming a free "does owner/repo exist" oracle (the concern documented on
   `userMayLookUpBranchesFor()`).
4. `repoSearch` is length-capped (100 chars) and trimmed; listing calls are rate-limited per
   user (e.g. 30 per minute) with `RateLimiter`.
5. `launchAudit()` is unchanged: it still runs `preflight()` before any charge, so a repo the
   token can no longer read is still caught there.

### Form UI (`audit-reports.blade.php`)

- No connections: current markup unchanged, plus a "Connect an account" link to
  `GitConnections::getUrl(...)` (shown only to members who may manage connections; others see
  today's text).
- One or more connections:
  - Provider switcher (only providers with a stored connection).
  - Searchable dropdown of `RepositoryEntry` items (full name, lock icon for private repos),
    20 most recently pushed first, "Load more" when `hasMore`.
  - "Paste a URL instead" link reveals the existing URL input and hides the picker (and vice
    versa).
- After a repo is chosen the existing branch selector loads exactly as it does today.
- The `?repo=` email link keeps pre-filling the URL input (URL mode).
- States: empty result → "No repositories found for this account" (GitHub adds: organisation
  repos appear only if the organisation approved the app); transient failure → the existing
  "couldn't reach <provider>, try again in a minute" notification with the URL box still
  available; revoked token → reconnect prompt with the Git Connections link.

## Data flow

1. Member opens the page → page renders picker if the workspace has a connection and the member
   may manage connections.
2. Member selects a provider → `loadRepositories()` fetches page 1 (cache-first).
3. Member types in search → debounced `repoSearch` → page 1 of the filtered list.
4. Member picks an entry → `chooseRepository(url)` validates (invariants 1–3) → sets `repoUrl`
   → `loadBranches()` → branch selector.
5. Member picks tier and launches → unchanged `launchAudit()` path.

## Testing

PHPUnit, factories, FeatureTest shared-suite rules (own tenants/users, restore any Config row,
no global-count assertions).

- **Providers (Http::fake):** each provider — first page, next page (`hasMore`), search term,
  empty result, 401/`invalid_grant`, 5xx/timeout mapped to the transient exception, response
  with missing optional fields; canonical URL shape; cache hit avoids a second HTTP call.
- **Resolver entry point:** returns a refreshed connection for a provider; null when none;
  transient exception propagates.
- **Page (Livewire):**
  - no connection → URL box only, connect link shown to permitted members;
  - with connection → picker listed, provider switch, search, load more;
  - choosing an entry sets `repoUrl` and loads branches;
  - `chooseRepository()` rejects a URL not in the current listing, a provider mismatch and a
    non-canonical URL;
  - member without tenant-settings permission cannot call any picker method (403) and sees no
    picker;
  - another workspace's connection is never used;
  - rate limit trips after the cap;
  - transient failure shows the notification and leaves URL mode usable; revoked token shows
    reconnect;
  - full launch from a picked repo charges/queues identically to a pasted URL.
- **Regression:** existing `AuditReportsPageTest` cases and the `?repo=` link stay green.

## Open items to settle in the plan (not blockers to the design)

1. **Provider scopes.** Confirm against current docs that the scopes already requested
   (`repo`, `read_api`, `repository`) permit the listing calls above. If one does not, add the
   minimal extra scope and a "reconnect to enable the picker" hint for existing connections
   (the picker falls back to URL mode meanwhile).
2. **GitHub search.** Decide between filtering the user's repos client-side after fetching
   pages and the search API (`q=…+user:…`), based on rate limits and organisation coverage.
3. **Rate-limit numbers** and cache TTL stay configurable in `config/audit.php`.

## Risks

- **Organisation visibility on GitHub:** OAuth-App tokens see organisation repos only after the
  organisation approves the app; the empty state explains this.
- **Provider rate limits:** mitigated by the 5-minute cache, page size 20, and per-user rate
  limit.
- **Oracle risk:** mitigated by invariants 1–4 above; the picker returns only repos the
  member's own workspace connection can already list.
