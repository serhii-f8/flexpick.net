# Admin audit & email UI — design

**Date:** 2026-08-07
**Status:** Approved for planning
**Scope:** The `/admin` panel only. The tenant dashboard panel (`app/Filament/Dashboard/`) is untouched.

## Problem

The admin panel's audit and email surfaces present correct data crudely, and the crudeness costs
real time during triage.

- `AuditAdminStatsWidget` renders ten equal-weight tiles. A `0` and a `47` look identical, so the
  block reads as wallpaper. Nothing links anywhere except queue depth. "Email failures" is an
  all-time count with no window.
- `AuditsByPlanWidget` emits one `Stat` per plan, so the grid is ragged by construction.
- Neither audit widget declares `$sort`, so their tiles interleave with the SaaS revenue widgets
  (`MetricsOverview` 0, MRR/TotalRevenue 1, Churn/Conversion 2, ARPU 3) on a dashboard that also
  carries a global date-range filter both audit widgets silently ignore.
- `AuditRequestResource`'s infolist crams twelve entries into one `Request` section and renders
  `pipeline_log` as monospace `pre` text.
- `AuditEmailLogResource` has no link back to the audit a message belongs to, no way to see what
  was sent before resending it, and no failure-window filter.
- The `Audits` navigation group is declared ad-hoc via `getNavigationGroup()` and never registered
  in `AdminPanelProvider::navigationGroups()`, so it is the only group without an icon.

## Goals

1. **Ops triage** — surface what is broken now, and link straight to it.
2. **Visual coherence** — Filament-native presentation, no hand-rolled HTML tables, no raw `pre`
   dumps.

Explicit non-goals: business-metrics depth (trend charts, funnel analytics), a dedicated Audit Ops
page, and bulk email operations.

## Approach

Triage conditions live as **query scopes on the models**, reading their thresholds from existing
config. The widget calls `->count()` on a scope; a resource tab hands the same scope to
`Tab::modifyQueryUsing()`. Filament's list-page tabs require a `Builder` modifier, which is exactly
what a scope is — so one definition serves both, and a dashboard tile and its drill-down target can
never disagree about what "stuck" means.

Two alternatives were considered and rejected. An `AuditOpsSnapshot` service returning a struct of
counts reads tidier, but it returns numbers while the tabs need queries, so the tabs would
re-derive the same conditions and reintroduce the drift the service was meant to prevent. Rendering
the stored `spatie/laravel-health` check results cannot disagree with the pager, but results
refresh only every five minutes — a tile could show green while an audit is actively wedged — and
the checks return pass/fail messages rather than the per-record sets the tabs need. One idea is
borrowed from it: a health-result freshness line under the ops block.

## Section 1 — Foundation: one shared triage vocabulary

### Scopes

`App\Models\AuditRequest`:

| Scope | Definition |
| --- | --- |
| `scopeStuck()` | `queued`/`new` created before `now() - oldest_queued_minutes`, **or** `analyzing` whose start is before `now() - oldest_analyzing_minutes` |
| `scopeNeedsManualAction()` | status in `needs_followup`, `awaiting_access`, `awaiting_payment` |
| `scopeBreachingExpertReviewSla()` | `tier = expert`, `status = expert_review`, `analysis_completed_at` before `now() - expert_review_sla_hours` |

For `scopeStuck()`, an `analyzing` row whose `analysis_started_at` is null falls back to
`updated_at`. A pipeline that died before stamping its start must still age into the stuck bucket
rather than hiding there forever.

`App\Models\AuditEmailLog`:

| Scope | Definition |
| --- | --- |
| `scopeFailedWithin(?int $hours = null)` | status `failed` or `bounced`, `sent_at` within the window |
| `scopeAttemptedWithin(?int $hours = null)` | any row with a non-null `sent_at` in the window — the delivery-rate denominator |

Both default `$hours` to `config('health.flexpick.mail_failure.window_hours')`. Both require a
non-null `sent_at`: a `pending` row has not failed, it has not been attempted.

No scope is added for plain `status = failed`. A single `where` in a tab is clearer than
indirection.

### Relation

`AuditRequest::emailLogs(): HasMany` — the inverse of the existing `AuditEmailLog::auditRequest()`.
`audit_request_id` is already on the table and already fillable; only the relation is missing.

### Thresholds

Reused from `config/health.php` (`flexpick` block), so a red tile and a pager alert are driven by
the same numbers:

- `oldest_queued_minutes` (30)
- `oldest_analyzing_minutes` (30)
- `mail_failure.window_hours` (24)

One new key is added to **`config/audit.php`**, not `health.php`:

```php
'expert_review_sla_hours' => (int) env('AUDIT_EXPERT_REVIEW_SLA_HOURS', 24),
```

It lives there because it is a delivery promise to a customer, not a system-health threshold, and
`config/audit.php` already owns that class of business config (`free_reports_limit`, `queue`). The
split is deliberate: each value lives where its meaning lives.

### Status triage classification

`AuditAdminStatsWidget::statusBuckets()` is replaced by a triage classification on `AuditRequest`
that maps every `AuditRequestStatus` case to exactly one of:

`in_flight` · `needs_manual_action` · `failed` · `expert_review` · `terminal`

This preserves the exhaustiveness invariant currently guarded by
`tests/Unit/AuditAdminStatsWidgetTest.php` — a newly added status cannot be silently ignored —
on the new structure rather than deleting it along with the old method.

## Section 2 — The `/admin` dashboard block

### `AuditAdminStatsWidget`

Keeps its class name. Rebuilt as a problem-first block: `$sort = 10`, `getHeading()` →
*"Audit operations"*, six tiles replacing ten.

| Tile | Value | Description | Links to |
| --- | --- | --- | --- |
| Failed audits | `status = failed` in the last 24h | — | Audit Requests, `?activeTab=failed` |
| Stuck in pipeline | `scopeStuck()` | "queued >30m or analyzing >30m" | Audit Requests, `?activeTab=stuck` |
| Needs manual action | `scopeNeedsManualAction()` | per-status counts, e.g. "2 awaiting access · 1 awaiting payment" | Audit Requests, `?activeTab=needs-action` |
| Expert review overdue | `scopeBreachingExpertReviewSla()` | age of the oldest waiting review | Expert Reviews |
| Email failures | `scopeFailedWithin()`, 24h | ":rate% delivered over 7 days" | Audit Emails, `?activeTab=failed-24h` |
| Pipeline | queue depth | "avg :time · :n audits today" | Horizon (new tab) |

The Failed tile is windowed to 24h while its `failed` tab is all-time and newest-first. That is
intentional and not a mismatch: an all-time failure count never returns to zero, so it could never
go quiet, which is the property the whole block depends on. The tab exists to answer "show me the
failures", the tile to answer "did something break today".

The email tile's delivery rate spans **7 days** — `scopeFailedWithin(168)` over
`scopeAttemptedWithin(168)` — while its count and its tab use the 24h `mail_failure.window_hours`.
A rate needs a wider base than an alarm to be meaningful.

Tab keys are `all`, `needs-action`, `failed`, `stuck`, `expert-review`; the tile URLs reference
those keys exactly.

The four signals that were dropped as standalone tiles — total volume, today/week/month split,
average processing time, queue depth — are folded into descriptions, where they read as context
rather than noise.

**Quiet at zero.** The five problem tiles render gray at zero with no icon, the description
"All clear", and **no URL** — nothing to click means nothing to chase. Non-zero flips them to
`danger` (failed, stuck) or `warning` (manual action, SLA, email) with an icon and a drill-down
URL. This is the core of the redesign: severity must be legible before the number is read.

**Page filter.** The ops widget deliberately ignores the dashboard's `start_date`/`end_date`/
`period` filter — "what is broken" is always *now*, and a date-ranged failure count is a trap. It
says so via `getDescription()`: *"Live · health checks last ran :n min ago"*, where the second
clause reads the freshness of the stored `spatie/laravel-health` results. A frozen scheduler
becomes visible instead of silently freezing every number on the block.

**Polling.** `$pollingInterval = '60s'`. Six indexed `COUNT`s per minute per open admin session.

### `AuditsByPlanWidget`

Converted from `StatsOverviewWidget` to `ChartWidget` (bar), `$sort = 11`. A variable-length stat
list produces a ragged grid; a bar chart handles one plan or nine equally well. Unlike the ops
widget, this one **respects** the dashboard date filter, replacing its hardcoded `startOfMonth()`
— it is a metric, not an alarm. Empty result renders an empty chart with an explanatory
description, not an exception.

## Section 3 — The two resources

### Audit Requests (`/admin/audit-requests`)

**List page** gains `getTabs()`: *All · Needs action · Failed · Stuck · Expert review*. Each tab is
built by handing the matching scope to `Tab::modifyQueryUsing()`, badged with its count and
coloured by severity. Badges use `deferBadge()` so five counts do not block first paint. These are
the same five queries behind the dashboard tiles, which is what makes the tiles' drill-down links
land somewhere real.

**Table** gains:

- an *Age* column (`created_at`, `->since()`),
- an *Emails* badge column (`->counts('emailLogs')`), red when any related log has failed,
- `recordClasses()` tinting rows that are failed or stuck.

No existing column is removed.

**Infolist** splits the single twelve-entry `Request` section into:

- **Request** — customer, repo, source, tier, consent, workspaces.
- **Timeline** — a `ViewEntry` rendering `pipeline_log` as a vertical rail: one dot per step, step
  name, relative time, message, failure steps in red. The stored data is already
  `['step', 'message', 'at']`, so this is a rendering change with no data migration. Empty log
  keeps the existing "No processing activity recorded yet." copy.
- **Results** — scores, finding groups, link to the report.
- **Emails** — a `RepeatableEntry` over `emailLogs()`: recipient, mailable, status badge, attempts,
  each linking into the email log. This replaces a manual cross-reference.
- **Next-run prompt preview** — unchanged, still collapsed.

### Audit Emails (`/admin/audit-email-logs`)

- **Repository column** from `auditRequest.repo_url`, linking to that audit's view page.
- **Tabs**: *All · Failed (24h) · Bounced · Pending*, the failure tab driven by
  `scopeFailedWithin()`.
- **Header widget** — a compact stats strip above the table: 7-day delivered rate, attempted,
  failed. Same window and same scopes as the dashboard's email tile, so the two cannot disagree.
- **Preview action** alongside Resend, opening the stored body in a modal. The body renders inside
  a **sandboxed `<iframe srcdoc>`**, never inline. Stored bodies are complete HTML documents with
  their own `<style>` blocks; injecting one into the admin page would bleed its CSS across the
  panel and execute whatever the template contains. The iframe is a correctness requirement.
  Hidden when `body` is empty, matching the existing Resend visibility rule.
- Resend keeps its existing confirmation modal. **No bulk resend** — mass-mailing customers from a
  checkbox column is out of scope and out of appetite.

### Tidying

- `AuditFunnel`'s hand-rolled `<table>` with `border-t` gets Filament table styling. It stays in
  the `Settings` navigation group and its metrics are unchanged.
- `ExpertReviewResource` keeps its columns and query, gaining only label consistency.
- The `Audits` navigation group is registered in `AdminPanelProvider::navigationGroups()` with an
  icon and `collapsed()`, matching the seven groups already declared there.

## Section 4 — Error handling

The dashboard must never 500 because a dependency is down; an ops block that takes the panel with
it is worse than no ops block. The existing guards set the pattern — `queueDepth()` catches
`Throwable` and returns `—`, `emailFailures()` checks `Schema::hasTable` — and it extends to every
new source:

- Unreachable Redis → `—` on the Pipeline tile, rest of the block unaffected.
- No stored health results → the freshness line reads "health checks have never run" rather than
  dereferencing null.
- No plans with audits this month → empty chart plus description.
- Malformed `pipeline_log` entries (missing `step`/`at`, unparseable timestamp) render raw rather
  than throwing. That log is written by a pipeline that may have died mid-write, so half-formed
  entries are expected input, not corruption.

## Section 5 — Testing

PHPUnit `TestCase` classes (`php artisan make:test --phpunit`), run inside Docker. Not Pest.

**New — scope boundaries** (`AuditRequestScopesTest`, `AuditEmailLogScopesTest`):

- a record exactly *at* a threshold is excluded, not included;
- an `analyzing` row with null `analysis_started_at` ages off `updated_at`;
- a `pending` email with null `sent_at` is in neither the failure count nor the denominator.

**Ported — exhaustiveness:** `tests/Unit/AuditAdminStatsWidgetTest.php` is rewritten against the
new triage classification, asserting every `AuditRequestStatus` case falls into exactly one of
`in_flight` / `needs_manual_action` / `failed` / `expert_review` / `terminal`.

**New — widget behaviour:**

- at zero, a problem tile is gray and carries no URL; non-zero, it is `danger`/`warning` and its
  URL points at the correct `?activeTab=`;
- the ops widget's counts are unchanged by the page's `start_date`/`end_date` filter;
- `AuditsByPlanWidget`'s counts *do* change with it.

The last two pin the deliberate asymmetry from Section 2 so a later refactor cannot quietly
"fix" it.

**New — resources:** tab counts match their scopes; the email preview action is hidden when `body`
is empty; the repository column links to the right audit.

**Updated:** `tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php` asserts on `Total audits`,
`Analyzing`, and `4m`, all of which move into descriptions or disappear. It is rewritten against
the new tiles, not deleted.

## Files touched

| File | Change |
| --- | --- |
| `app/Models/AuditRequest.php` | three scopes, `emailLogs()` relation, triage classification |
| `app/Models/AuditEmailLog.php` | two scopes |
| `config/audit.php` | `expert_review_sla_hours` |
| `app/Filament/Admin/Widgets/AuditAdminStatsWidget.php` | rebuilt: six tiles, sort, heading, polling |
| `app/Filament/Admin/Widgets/AuditsByPlanWidget.php` | `StatsOverviewWidget` → `ChartWidget`, sort, respects filter |
| `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` | table columns, infolist split |
| `app/Filament/Admin/Resources/AuditRequests/Pages/ListAuditRequests.php` | `getTabs()` |
| `app/Filament/Admin/Resources/AuditEmailLogs/AuditEmailLogResource.php` | repo column, preview action |
| `app/Filament/Admin/Resources/AuditEmailLogs/Pages/ListAuditEmailLogs.php` | tabs, header widget |
| `app/Filament/Admin/Resources/ExpertReviews/ExpertReviewResource.php` | label consistency |
| `app/Providers/Filament/AdminPanelProvider.php` | register `Audits` navigation group |
| `resources/views/filament/admin/audit/pipeline-timeline.blade.php` | new |
| `resources/views/filament/admin/pages/audit-funnel.blade.php` | Filament table styling |

Test files, per Section 5: `tests/Unit/AuditAdminStatsWidgetTest.php` (rewritten),
`tests/Feature/Filament/Admin/AuditAdminWidgetsTest.php` (rewritten),
`tests/Feature/Models/AuditRequestScopesTest.php` (new),
`tests/Feature/Models/AuditEmailLogScopesTest.php` (new),
`tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` and
`AuditEmailLogResourceTest.php` (extended with tab and action coverage).

## Verification

`vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, and `php artisan test --compact` must all
pass. Per project convention, run plain `pint` (never `pint --dirty`, which reports a vacuous pass
inside the dev container) before finalizing.
