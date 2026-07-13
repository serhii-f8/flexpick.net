# User Dashboard Audit Management — Design

**Date:** 2026-07-13
**Status:** Approved design, pending implementation plan
**Scope:** Spec 3 of 4 from the 2026-07-13 feature sprint decomposition. Spec 1 covers foundations (seeders provide the demo data used to test this spec); Spec 2 covers the unified layout; Spec 4 covers admin audit management.
**Builds on:** the audit pipeline and freemium model (`2026-07-11-audit-freemium-intake-design.md`), `AuditEntitlementService`, and the existing dashboard `AuditReports` page.

## Problem

Dashboard users can launch audits and see finished reports (existing `AuditReports` page), but they cannot see in-flight or failed audits, there is no per-audit details page, and the main dashboard has no audit statistics. Queued, analyzing, failed, and awaiting-access audits are invisible until an email arrives.

## Decisions made during brainstorming

| Question | Decision |
|---|---|
| Section structure | New read-only Filament Resource over `AuditRequest` (list + view); the existing `AuditReports` page stays as the launch/schedules/trends hub |
| Main-dashboard widgets | Two widgets: a 4-tile StatsOverview and a "Recent audits" table widget |
| Ownership scope | `user_id = auth id OR email = user's email`; `LinkAuditReportsToUser` listener extended to also backfill `audit_requests.user_id` on registration |

## Key discovered constraint

On registration, `app/Listeners/User/LinkAuditReportsToUser.php` links `audit_reports.user_id` by matching the request email — but leaves `audit_requests.user_id` null. A resource scoped by `user_id` alone would hide a user's pre-registration free audits. Both fixes apply: the query scope includes email matching, and the listener is extended to backfill request ownership.

## 1. Audits resource (read-only)

New Filament Resource `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php` (+ `Pages/ListAuditRequests.php`, `Pages/ViewAuditRequest.php`), navigation label **"Audits"**.

### Scoping and authorization

- `getEloquentQuery()` returns only the signed-in user's audits: `where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))`. This is the authorization boundary — no policy bypass path exists because every table/infolist/action flows through this query, and Filament resolves records via it (a foreign audit's UUID 404s).
- View-only: no create, edit, or delete pages; no bulk actions.
- Navigation visibility follows the same rule as the existing page: shown when the user has any audits or a positive subscription allowance.
- `app/Listeners/User/LinkAuditReportsToUser.php` gains a second update: `AuditRequest::whereNull('user_id')->where('email', $user->email)->update(['user_id' => ...])` so ownership is durable after registration.

### List page

Table over the scoped query, newest first:

| Column | Source |
|---|---|
| Repository / project | `repo_url`, placeholder "No repository" when null |
| Status | Badge colored by `AuditRequestStatus`: `queued`/`analyzing` → info; `report_ready`/`sent` → success; `failed` → danger; `needs_followup`/`awaiting_access`/`awaiting_payment` → warning; `new`/`pending_verification`/`handled` → gray |
| Score | `report.payload.scores.overall`, dash when absent |
| Source | `source` (web / dashboard) |
| Submitted | `created_at` |
| Completed | `report.created_at`, dash when absent |

Filters: status (multi-select), submitted date range. Row click → view page. Header link "Run new audit" to the existing `AuditReports` page, visible only when the user has remaining allowance.

### View page (details)

Infolist sections:

1. **Project** — repo URL (linked, external), submitted name/email, source, original message.
2. **Status & timeline** — status badge plus a plain-language description per status; `failure_reason` shown when failed; a callout for statuses needing user action: `awaiting_access` shows the invite-`flexpick-audit`-on-GitHub instructions, `needs_followup` shows "we need more information — check your email"; timestamps: submitted, email verified, completed (report `created_at`).
3. **Results** — rendered only when a report exists: overall score plus per-category scores (`structure`, `duplication`, `testing`, `dependencies`, `security_hygiene`), count of risks by impact, and actions **View online** (via `AuditReportService::signedUrl($report)`) and **Download PDF** (existing `reports.download` route). Locked reports (null `unlocked_at`) show both links too — the hosted report page itself handles locked-section rendering and the unlock purchase path, so no unlock logic is duplicated here.

## 2. Main-dashboard widgets

Registered in `DashboardPanelProvider` alongside `AccountWidget`; both use the same ownership scope as the resource and hide entirely (`canView(): false`) when the user has no audits and no allowance.

### `AuditStatsWidget` (StatsOverview, 4 tiles)

| Tile | Value |
|---|---|
| Analyses remaining this month | Subscribers: `remainingDashboardRuns(user, tenant)` of `subscriptionAllowance(tenant)`, description shows usage % (`used/allowance`). Users without allowance: free runs — `freeRunsLimit(email) - freeRunsUsed(email)` of `freeRunsLimit(email)`, labeled "Free audits remaining". |
| In progress | Count with status `queued` or `analyzing` |
| Completed | Count with status `report_ready` or `sent` |
| Failed | Count with status `failed` |

All from `AuditEntitlementService` plus scoped count queries — no new service methods beyond a small scoped-query helper shared with the resource (a `forUser(User $user)` scope on `AuditRequest`).

### `RecentAuditsWidget` (TableWidget)

Last 5 scoped audits: repo, status badge, submitted date; row link to the resource view page.

## 3. What stays as-is

- The existing `AuditReports` custom page: launching audits (`launchAudit`), schedules (`setSchedule`), re-audit trend sparklines, and its entitlement gates — untouched.
- The hosted web report, PDF download, unlock purchase flow — reused via links, not reimplemented.
- Admin-side audit management — Spec 4.

## 4. Testing

Feature tests (using Spec 1's demo seeder states where convenient):

- **Isolation:** user A's list shows A's audits and not user B's; requesting B's audit UUID via the view page 404s.
- **Email-matched ownership:** an audit with `user_id = null` but matching email appears in the list; after the `Registered` event, its `user_id` is backfilled (listener test).
- **View page:** renders failure reason for a failed audit, awaiting-access instructions for `awaiting_access`, and report links + scores for a completed audit.
- **Widgets:** stat values correct for a subscribed user (allowance/used/remaining/%), a free-quota user (free runs), and the exhausted demo user (0 remaining); widgets hidden for a user with no audits and no allowance.
- **Navigation:** Audits nav item hidden for a user with no audits/allowance.

## Out of scope (later specs)

- Admin audit management, prompt/context editing, email notification tracking, admin widgets (Spec 4).
- Changes to the launch/schedule flows or the report pages.
