# Multi-provider repo access, tenant OAuth, and size-based pricing — design

Date: 2026-09-27 · Branch: `growth-retention`

## Problem

Three things the 2026-09-24 repo-access spec explicitly deferred are now in
scope together, because they share one root cause:

1. **Cross-workspace access (security).** `RepositoryCloner::authenticatedUrl()`
   embeds one shared `config('audit.github_token')` into every clone, and
   nothing scopes a `repo_url` to the tenant that's entitled to audit it. A
   signed-in user of workspace B can submit workspace A's private repo and get
   the full report, because the shared token is a collaborator on every
   customer's private repos.
2. **GitLab and Bitbucket support.** Today's flow is hardcoded to GitHub —
   `GitHubApiClient::parseRepo()`, `RepositoryCloner`'s `github.com` check,
   and the access-needed copy all assume one provider.
3. **Large repos as multiple runs.** No repo-size signal feeds pricing today;
   a 5k-line repo and a 500k-line repo cost the same number of credits.

(1) and (2) are the same fix: replace "invite our shared bot account" with
"each tenant connects their own account per provider via OAuth." A repo can't
be cloned unless the *requesting tenant's own* token can read it — the
security hole closes structurally, and provider support becomes "add another
`GitProvider` implementation," not "build a second invite flow."

(3) is independent of provider — it plugs in after clone via `scc`,
regardless of which provider supplied the repo — but ships in the same spec
because it touches the same pipeline stage and the same admin-panel work.

## Goals

- A tenant connects GitHub, GitLab, and/or Bitbucket Cloud accounts via
  OAuth from the workspace dashboard. No shared bot account, no manual invite
  acceptance, no "usually within one business day" promise — OAuth consent is
  instant.
- `RepositoryCloner` clones with the connecting tenant's own token. A repo
  outside that token's read access simply cannot be audited by that tenant.
- Repo size (LOC via `scc`) determines how many runs/credits an audit costs,
  configured from the admin panel, with pricing copy generated from the same
  settings.
- Insufficient credit after sizing pauses nothing indefinitely: the request
  closes and refunds, same shape as `not_analyzable`, telling the customer to
  buy credit and start a new audit.

## Non-goals

- Self-hosted/on-prem GitLab or Bitbucket Server — cloud-hosted
  (`github.com`, `gitlab.com`, `bitbucket.org`) only.
- Auto-accepting invites — moot: OAuth has no invite-acceptance step.
- Resuming a paused request in place — `awaiting_credit` follows the
  close-and-refund pattern, not a resume-in-place pattern.

## Architecture

### `GitProvider` interface

One implementation per provider (`GitHubProvider`, `GitLabProvider`,
`BitbucketProvider`), selected by the `repo_url` host. Each implements:

- `authorizationScopes(): array` — the OAuth scope(s) needed for repo read
  (GitHub `repo`, GitLab `read_repository`, Bitbucket `repository:read`).
- `listRepos(TenantGitConnection $connection): array`
- `listBranches(TenantGitConnection $connection, string $repoUrl): array`
- `cloneUrl(TenantGitConnection $connection, string $repoUrl): string` —
  embeds that tenant's own token.
- `checkAccess(TenantGitConnection $connection, string $repoUrl): bool`
- `parseRepo(string $repoUrl): array` (owner/repo, replaces
  `GitHubApiClient::parseRepo()`)

`GitHubApiClient` folds into `GitHubProvider`; `RepositoryCloner` stops
special-casing `github.com` and instead resolves a provider by host, then
resolves that tenant's `TenantGitConnection` for it.

### `tenant_git_connections` (new table)

No existing per-tenant *secret* storage convention exists to reuse —
`TenantParameter` is the closest tenant-scoped key/value store but has no
`encrypted` cast (used today for credit counters, not secrets), and the
existing OAuth token storage (`OAuthController`, `UserParameter` rows) is
user-scoped, login-only, and also unencrypted. This introduces the app's
first encrypted-cast credential storage:

| column             | notes                                   |
|--------------------|------------------------------------------|
| `tenant_id`        | owner; `scopeForTenant()`-style access    |
| `provider`         | `github` \| `gitlab` \| `bitbucket`       |
| `account_login`    | display only                              |
| `access_token`     | `encrypted` cast                          |
| `refresh_token`    | `encrypted` cast, nullable                |
| `expires_at`       | nullable (GitHub OAuth-app tokens typically don't expire; GitLab/Bitbucket do) |
| `connected_by_user_id` | audit trail                           |
| `connected_at`     |                                            |

Unique on `(tenant_id, provider)` — one connection per provider per tenant.

## Connection flow

New routes distinct from login OAuth (which stays as-is, identity only):
`GET /workspace/git-connections/{provider}/redirect` →
`Socialite::driver($provider)->scopes($provider->authorizationScopes())->redirect()`,
and a callback storing the result into `tenant_git_connections` (not
`UserParameter`). Token refresh for providers that expire tokens (GitLab,
Bitbucket) runs lazily at use time; a failed refresh marks the connection
invalid and prompts reconnect — same UX as never having connected.

**Migration:** the shared `audit.github_token` model is retired outright, not
kept as a fallback — a fallback that still lets any tenant clone any repo the
bot was invited to defeats the fix. Existing tenants see "connect your GitHub
account" the next time they start or schedule an audit. Nothing about past
reports changes. Recurring `AuditSchedule` runs for a not-yet-reconnected
tenant fail through the existing `not_analyzable` + refund path (4f9944b) with
new copy, not a new failure mode.

## Data flow & error handling

- **Landing page (anonymous).** No tenant exists yet, so there is no
  connection to check — this flow is now explicitly public-repos-only. A
  private repo fails immediately with "sign up and connect your account to
  audit private repos"; no admin/bot-invite step. The `awaiting_access` status
  class is retired for new requests (legacy rows keep their operator actions,
  per the 2026-09-24 spec).
- **Dashboard (authenticated tenant).** Preflight checks
  `tenant_git_connections` for a live connection matching the URL's provider
  *before* charging (same preflight-before-charge shape already shipped). No
  connection, or `checkAccess()` fails → "connect/reconnect your account,"
  nothing charged.
- **Mid-pipeline failures** (token revoked between preflight and clone, expired
  token with failed refresh): same `AuditNotAnalyzableException` →
  `AuditRequestService::closeNotAnalyzable()` → refund path as 4f9944b, with a
  new failure-reason string driving "reconnect your account" copy instead of
  "accept our invite." `AuditRepoAccessNeeded` gets a second copy variant for
  this reason, alongside the existing "repo too large" / generic variant.

## Multi-run sizing

- 1 run is charged at request start (existing charge point, unchanged).
- After clone, `scc` counts LOC (`SccInventory::$totalLoc`, already collected
  post-clone/pre-AI); admin-configured thresholds determine total runs owed
  (e.g. ≤100k LOC → 1 run, ≤300k → 2 runs). These are two distinct outcomes,
  both closing the request the same way (refund + `awaiting_credit`, below),
  but for a different reason string:
  - **Above the top configured band** (no defined run-count for this size) —
    "too large for self-serve, contact us" reason. This is a size ceiling, not
    a balance problem — it recurs even after the tenant buys more credit.
  - **Within a defined band, balance can't cover the computed run count** —
    ordinary insufficient-credit reason. Buying enough credit and re-running
    resolves it.
- Balance covers the computed run count → charge the difference, proceed into
  `AiAnalyzer` normally.
- Either closing case → refund the 1 run already charged (size analysis
  is clone + `scc` only, no AI spend yet, so nothing chargeable was consumed)
  and close the request with a new terminal status `awaiting_credit`, same
  shape as `not_analyzable`: email to buy credit / upgrade and start a new
  audit, Launch/Retry hidden, one refund path reused rather than a second
  "resume in place" mechanism.
- Threshold bands live in the admin panel (new settings, not hardcoded);
  plan/pricing-page descriptions are generated from the same settings so copy
  and behavior can't drift apart.

## Testing

- `Socialite::fake()` for connect-flow redirect/callback, per provider.
- `GitProvider` fakes for `RepositoryCloner` branching (no real API calls in
  unit tests); a `FakeGitProvider`/connection-seeding helper alongside the
  existing `RunsAuditPipelineWithFakes` trait for pipeline feature tests.
- `access_token`/`refresh_token` encrypted-cast round-trip test (raw DB column
  isn't cleartext).
- `awaiting_credit`: refund-once + close, mirroring the existing
  `not_analyzable` refund tests (idempotent refund, correct handling per
  funding kind).
- Admin: threshold config + generated pricing copy read from the same
  settings (single source, no drift).
- Retired paths: `awaiting_access` no longer reachable from new landing-page
  requests; shared-token fallback absent (a tenant with no connection cannot
  clone via any leftover shared credential).

## Out of scope / noted

- Self-hosted GitLab/Bitbucket Server (would need per-instance host config and
  network access to customer infrastructure).
- A UI repo-picker backed by `listRepos()` is enabled by this design but not
  required by it — the free-text `repo_url` input can stay, validated against
  `checkAccess()`.
