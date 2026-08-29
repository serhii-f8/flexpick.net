# Audit Reports Page UX — Design

Date: 2026-08-29
Status: Approved for planning

## Problem

`App\Filament\Dashboard\Pages\AuditReports` (`backend/app/Filament/Dashboard/Pages/AuditReports.php` +
`backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`) is the tenant-facing
page where users launch one-off audits, configure recurring (scheduled) audits, and review past
reports per repo. Four UX gaps make it hard to use:

1. The "score history" chart is a bare inline SVG polyline with no color, markers, or tooltips —
   score movement is hard to read at a glance.
2. Scheduled audits only support a `frequency` (`weekly`/`monthly`) computed against `last_run_at`.
   There's no way to pick *which day* a weekly audit runs, and no way to see upcoming runs.
3. Every due scheduled audit re-clones and re-analyzes the repo even if nothing changed since the
   last run, burning quota and compute for no new signal.
4. Neither the one-off "launch audit" form nor the "schedule" form let a user target a specific
   branch — audits always run against the repo's default branch (`RepositoryCloner` always clones
   with `--single-branch`, no `--branch` argument, no branch column anywhere).

## Current state (relevant facts)

- `AuditSchedule` (`app/Models/AuditSchedule.php`): fillable
  `user_id, tenant_id, repo_url, frequency, tier, last_run_at`. No `next_run_at`, `day_of_week`,
  or `branch` column.
- `RunScheduledAudits` (`app/Console/Commands/RunScheduledAudits.php`): loads *all* schedules into
  memory, filters in PHP via
  `last_run_at === null || last_run_at <= (frequency === 'weekly' ? now()->subWeek() : now()->subMonth())`.
  For each due schedule: checks quota via `AuditEntitlementService`, creates an `AuditRequest`,
  dispatches `GenerateAuditReport::dispatch()`, stamps `last_run_at = now()`.
- `RepositoryCloner::clone()` (`app/Services/AuditReport/RepositoryCloner.php`) shells out to
  `git clone --depth N --no-tags --single-branch <authenticated-url> <path>` — no branch targeting.
  `authenticatedUrl()` injects a single shared PAT (`config('audit.github_token')`, env
  `AUDIT_GITHUB_TOKEN`) as `x-access-token:{token}@github.com/...`, used only for git-protocol
  clone auth, not GitHub's REST API.
- No GitHub REST API client exists anywhere in the backend. Socialite GitHub OAuth login exists
  (`app/Http/Controllers/Auth/OAuthController.php`) and stores a per-user token in
  `user_parameters`, but with only the default `user:email` scope — not usable for repo API calls,
  and only covers users who logged in via GitHub.
- No commit-SHA (or any diff-able identifier) is stored anywhere. `GitFactsCollector` captures
  `last_commit_at` (a timestamp) and `default_branch` into the report's `payload` JSON, but nothing
  queryable.
- The score chart (`audit-reports.blade.php` lines ~86-97) is computed entirely server-side in
  Blade/PHP from `payload.scores.overall`, rendered as a single-color `<polyline>`. No JS chart
  library is installed anywhere in the app (confirmed: no ApexCharts/Chart.js in `resources/` or
  `package.json`).

## Goals

1. Make score movement visually obvious (direction, magnitude, per-point detail) without adding a
   JS charting dependency.
2. Let users pick a day of the week for weekly scheduled audits, and see upcoming (and recent)
   scheduled-audit occurrences on a calendar.
3. Skip a scheduled audit run (and show that it was skipped) when the target branch hasn't changed
   since the last run, instead of always re-running.
4. Let users target a specific branch — fetched live from GitHub — both for a one-off audit launch
   and for a schedule.

## Non-goals

- Monthly schedules do not get day-of-week/occurrence-of-month picking (e.g. "2nd Tuesday") — only
  weekly schedules gain `day_of_week`. Monthly keeps today's "same day-of-month as last run"
  behavior, unchanged.
- No new Composer/npm dependency for charting or the calendar (no ApexCharts/Chart.js, no
  `saade/filament-fullcalendar` or similar). Both are hand-built with Blade/Alpine.js/inline SVG,
  consistent with the page's current all-server-rendered approach.
- No change to the per-user GitHub OAuth scope. Branch listing reuses the existing shared
  `AUDIT_GITHUB_TOKEN` PAT (the same credential already trusted for private-repo cloning) via a new
  small GitHub REST API client — not per-user OAuth.
- `AuditDeltaService` / `AuditBenchmarkService` (cross-run scoring comparisons) are out of scope;
  this design only touches the score-history *chart* rendering, not scoring logic.

## Design

### A. Data model & migrations

**`audit_schedules`** gains three nullable columns:

- `branch` (`string`, nullable) — target branch for this schedule; `null` means "repo's default
  branch," identical to today's behavior.
- `day_of_week` (`unsigned tinyint`, nullable, 0=Sunday..6=Saturday) — required (validated in app
  code) when `frequency = 'weekly'`; unused when `frequency = 'monthly'`.
- `last_commit_sha` (`string`, nullable) — the commit SHA observed at the last *completed*
  dispatch, used as the comparison baseline for the change check.

**`audit_requests`** gains one nullable column:

- `branch` (`string`, nullable) — one-off branch override for a manually launched audit. Same
  null-means-default semantics. Threaded through to `RepositoryCloner`.

**New table `audit_schedule_runs`** — one row per schedule *evaluation* (not just successful runs),
giving the calendar a real history to render:

- `audit_schedule_id` (FK → `audit_schedules`)
- `scheduled_for` (`date`) — the calendar day this evaluation belongs to
- `status` (`string`: `completed` | `skipped`)
- `reason` (`string`, nullable) — populated only when `status = skipped` (`no_changes` |
  `no_quota`)
- `audit_request_id` (FK → `audit_requests`, nullable) — set when `status = completed`
- `commit_sha` (`string`, nullable) — the SHA seen during this evaluation
- timestamps

Upcoming (future) calendar occurrences are **computed on read**, not stored — projected forward
from `frequency` / `day_of_week` / `last_run_at` using the same due-date math as the console
command, just projected forward instead of only filtering backward-looking due-ness. Only past
occurrences (what actually happened) come from `audit_schedule_runs`.

### B. Backend services & flow

**1. `GitHubApiClient`** (new — `app/Services/GitHub/GitHubApiClient.php`)

```
listBranches(string $repoUrl): array<string>
```

Parses `owner/repo` out of a `github.com` URL (regex on `github\.com[:/]([^/]+)/([^/.]+)`), then
calls `GET https://api.github.com/repos/{owner}/{repo}/branches?per_page=100` via
`Http::withToken(config('audit.github_token'))`. Returns an empty array — never throws — on any
failure: non-GitHub URL, unparseable URL, 404/403 (private/inaccessible repo), network error, or
rate limit. Callers treat an empty result as "branch listing unavailable," not an error state.

**2. `RepositoryCloner::clone()`** gains an optional `?string $branch = null` parameter. When set,
appends `--branch <branch>` to the existing `git clone --depth N --no-tags --single-branch ...`
argument list (git supports combining `--single-branch` with `--branch` — clones only that one
branch). `AuditPipeline` reads `$auditRequest->branch` and passes it through.

**3. Change check** (new — a small method on `RunScheduledAudits`, extracted as
`ScheduledAuditChangeChecker` service for testability). For each due schedule:

- Resolve the SHA to compare: `git ls-remote <url> refs/heads/<branch>` when `schedule->branch` is
  set, otherwise `git ls-remote <url> HEAD` (resolves the remote's default branch symref) when
  `null`.
- **Same SHA as `schedule->last_commit_sha`** → do not dispatch, do not touch
  `last_run_at`/`last_commit_sha`. Insert an `audit_schedule_runs` row
  (`status=skipped, reason=no_changes, commit_sha=<the SHA>`). The schedule remains "due" on the
  next cron tick for its cadence — this is a same-cycle skip, not a reschedule.
- **Different SHA, or no prior `last_commit_sha` (first run)** → proceed exactly as today: check
  quota, create `AuditRequest`, dispatch `GenerateAuditReport`. On successful dispatch, stamp
  `last_run_at = now()`, `last_commit_sha = <the new SHA>`, and insert an `audit_schedule_runs` row
  (`status=completed, audit_request_id=<id>, commit_sha=<the SHA>`).
- **No quota left** → unchanged from today (skip, `$this->warn(...)`), but now also insert an
  `audit_schedule_runs` row (`status=skipped, reason=no_quota`) so the calendar reflects it.
- **`git ls-remote` itself fails** (network error, transient GitHub outage, repo briefly
  inaccessible) → **fail open**: treat this exactly like "different SHA" and proceed with the
  normal run (dispatch, stamp `last_run_at`). A transient check failure must never silently stop a
  schedule from ever running again. Since no SHA was actually read, `last_commit_sha` and the
  `audit_schedule_runs.commit_sha` for this row are left `null` rather than fabricated — the next
  cycle's check will then see a null baseline and correctly treat *any* readable SHA as "changed."

**4. Due-check logic update** (`RunScheduledAudits`): weekly schedules are due when
`now()->dayOfWeek === $schedule->day_of_week` **and** (`last_run_at` is null or
`last_run_at->isBefore(today())`) — the date guard prevents double-firing if the daily scheduler
tick runs more than once on the same day. Monthly due-check is unchanged
(`last_run_at <= now()->subMonth()`).

### C. UI

**Score chart** — rewrite the existing Blade SVG partial (`audit-reports.blade.php`), still pure
server-rendered SVG, no JS:
- Per-segment stroke color: rising segment green (`text-emerald-500`), falling segment red
  (`text-rose-500`), flat gray.
- A circle marker at each data point; marker radius scales slightly with the magnitude of change
  from the previous point.
- Light horizontal gridlines at fixed score bands (e.g. 25/50/75).
- A `<title>` element per marker for native browser tooltips: `"78 → 85 (+7) on Aug 20, 2026"`.

**Branch selector** — a `wire:model`-bound `<select>` placed next to the repo-URL input on both the
"launch audit" and "schedule" forms. A Livewire action (debounced on URL blur/change) calls
`GitHubApiClient::listBranches()` and populates the select, with a "Repo default branch" option
always first and pre-selected. When the lookup returns an empty array (non-GitHub URL, private repo
not accessible, lookup failure), the select is replaced by a plain optional text input with helper
text ("branch not found — you can still type one, or leave blank for the default branch").

**Calendar** — a Blade partial rendering a month grid (Alpine.js for prev/next month navigation,
no page reload), fed by `getViewData()` merging: computed upcoming occurrences (projected forward,
undecided outcome — rendered as a neutral "scheduled" marker) with persisted `audit_schedule_runs`
rows for past days (green dot = completed, gray dot = skipped, with the `reason` in a tooltip).
Scoped per repo, next to that repo's existing schedule controls.

### Testing

Per the repo's TDD workflow (PHPUnit, `--filter` per class), red-then-green for each unit:

- `GitHubApiClient`: `Http::fake()` cases for a valid public repo, a 404/private repo, a
  non-GitHub URL, and a malformed URL — asserts branch list vs. empty-array fallback.
- `RepositoryCloner`: asserts the `--branch` argument is present/absent in the `Process` command
  array based on the `$branch` parameter.
- `RunScheduledAudits` / change-check: table-driven cases — same SHA (skip, no dispatch, no
  `last_run_at` change), different SHA (dispatch + stamps), first run / null `last_commit_sha`
  (dispatch), no quota (skip + `audit_schedule_runs` row), `ls-remote` failure (fail-open: dispatch
  proceeds).
- Due-date math: weekly `day_of_week` matching/non-matching today, same-day double-fire guard,
  monthly unchanged.
- Livewire test on `AuditReports`: branch select populates from a faked `GitHubApiClient`, falls
  back to text input on empty result, selected branch is persisted onto the created
  `AuditRequest`/`AuditSchedule`.
- Larastan/Pint gates per `backend/CLAUDE.MD` — `vendor/bin/phpstan analyse` and
  `vendor/bin/pint --test` must stay clean.

## Open questions

None outstanding — all four scope decisions (chart approach, GitHub auth method, change-check
fail-open/closed, day-of-week scope, calendar implementation) were confirmed during brainstorming.
