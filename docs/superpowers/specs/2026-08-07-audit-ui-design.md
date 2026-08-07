# Audit UI — dashboard widgets and the public report

**Date:** 2026-08-07
**Depends on:** `bdb49fa` (free-run dashboard access), payload v4, `AuditEntitlementService`
**Covers:** `app/Filament/Dashboard/**`, `resources/views/reports/**`

---

## 1. Scope

Two surfaces, one spec. They are kept as separate parts throughout because they run on
different rendering stacks with incompatible constraints, and blending them produces a plan
too vague to execute.

- **Part A — Dashboard.** Filament panel, dark by default (`DashboardPanelProvider`
  `->defaultThemeMode(ThemeMode::Dark)`). The design system is already available here; the
  audit screens simply bypass it.
- **Part B — Public report.** `resources/views/reports/audit-web.blade.php`, a standalone HTML
  document served by `AuditReportController`, plus its DomPDF twin `audit.blade.php`.

The two share exactly one thing: the `ReportPayload` shape. No shared markup, no shared CSS.

### 1.1 What is actually wrong today

This is a presentation problem, not a missing-design-system problem. The brand layer is in good
shape — `resources/css/colors.css` defines full 50–950 gold (`#d4a853`) and coral (`#e2694a`)
scales, the panel sets `->font('DM Sans')` and wires `primary` to the brand gold, and
`flexpick-daisyui.css` defines a coherent dark theme. The audit screens do not use any of it.

Concretely:

- **`audit-reports.blade.php`** (67 lines) builds report cards from raw
  `<div class="rounded-lg border p-4">`, computes an SVG polyline inline in the template
  (`$points = $scores->map(fn ($s, $i) => ...)`), and places a bare `<select>` with hand-written
  dark-mode classes next to Filament buttons. Cards show a repo URL and a date — neither status
  nor score.
- **`AuditStatsWidget`** renders up to seven equal-weight `Stat` tiles with no icons and mostly
  no descriptions. "Analyses remaining this month" carries the same visual weight as "Failed: 0".
- **`AuditRequestResource::infolist()`** renders category scores by imploding them into a string:
  `'Security: 80 · Performance: 70'`.
- **No plan context anywhere.** Plan name and renewal date exist only inside the Subscriptions
  resource.
- **`audit-web.blade.php`** carries 40 lines of inline `<style>`, is light-grey/Helvetica
  (`background: #fafaf9`, `color: #1c1917`) against a dark brand, and its single brand touchpoint
  is a hardcoded `#d4a853`. The deliverable customers judge the product on looks like a
  different product.

`AuditRequestResource`'s table and infolist are otherwise sound (badges, filters, sensible
columns) and need refinement, not rework.

---

## 2. Decisions taken

| Decision | Choice | Rationale |
| --- | --- | --- |
| Spec structure | One combined spec | User's call, against the recommendation to split. Mitigated by keeping Parts A and B strictly separate below. |
| Public report theme | Light, brand-aligned | Reports are read slowly, printed and forwarded. Keeps the readable light ground but replaces cold grey with warm neutrals, Helvetica with DM Sans, hardcoded hexes with the real token scales. |
| Web vs PDF | Extract logic, fork presentation | Moves ordering logic out of Blade, gives web a real design system, keeps the PDF DomPDF-safe. |
| Dashboard home | Plan hero, then status | Answers plan / limits / statistics / in-progress — the four things originally asked for — and establishes a hierarchy the current flat tile wall lacks. |
| Run action | Header button, not a second form | Avoids two copies of the submit form drifting apart. |
| Audit Reports page | Repo groups with trend | Preserves the one thing this page does that the Audits list does not. Chosen on the stated basis that most customers audit a few repos. |

---

## 3. Part A — Dashboard

### 3.1 New: `PlanUsageWidget`

`app/Filament/Dashboard/Widgets/PlanUsageWidget.php` plus
`resources/views/filament/dashboard/widgets/plan-usage-widget.blade.php`.

Full-width, `$sort = 0`, so it sits above everything else. A Blade-backed widget rather than a
`StatsOverviewWidget`, because progress bars are the whole point and `Stat` cannot render them.

Content, in priority order:

1. Plan name and renewal date, from
   `SubscriptionService::findActiveTenantSubscriptions($tenant)` → `$subscription->plan->name`
   and `ends_at`.
2. Analyses this month: `dashboardRunsUsedThisMonth()` over `subscriptionAllowance()`, as a
   labelled progress bar.
3. Deep AI credits: `deepAiRunsUsedThisMonth()` over `deepAiCredits()`. **Hidden entirely when
   `deepAiCredits() === 0`**, matching how `AuditStatsWidget` already treats it.
4. Free-tier users (no subscription): free runs used over `freeRunsLimit()`, replacing bars 2–3.
5. An Upgrade action linking to the Subscriptions resource, shown when there is no subscription
   or the allowance is spent.

Visibility reuses `AuditEntitlementService::hasAuditAccess()` — the same gate as every other
audit surface, per `bdb49fa`.

### 3.2 New: custom `Dashboard` page

`app/Filament/Dashboard/Pages/Dashboard.php`, extending `Filament\Pages\Dashboard`, adding
`getHeaderActions()` with a **Run audit** action linking to `AuditReports::getUrl()`.

Filament's built-in `Dashboard` exposes no header actions, so it must be subclassed and swapped
in `DashboardPanelProvider::pages()`. The action is gated on `hasAuditAccess()` so it does not
appear for users with no entitlement.

### 3.3 Changed widgets

**`AuditStatsWidget`** — sheds the quota stats (now in `PlanUsageWidget`) and becomes a pure
status widget. It renders **only non-zero buckets**, so "Failed: 0" and "Needs your action: 0"
stop competing for attention. Each surviving stat gains an icon and a description line.
`statusBuckets()` stays public and unchanged — `AuditStatsWidgetBucketsTest` asserts against it.

**`RecentAuditsWidget`** — adds a score column with a delta against the previous audit of the
same repo, and an empty state pointing at the Run audit action.

Two query notes, because the current implementation makes both easy to get wrong. Its query is
`AuditRequest::forUser(...)->latest()->limit(5)` with **no eager loading**, so reading
`$record->report` per row is an N+1 the moment a score column exists — `->with('report')` must be
added. The delta additionally needs the *previous* report for the same repo, which is not
reachable from the eager-loaded relation. Resolve it with **one** extra query fetching prior
scores for just the repos in view, then compute deltas in PHP; do not query per row.

**`AuditRequestResource`** — replaces the imploded category-score string in `infolist()` with a
bar per category. Table columns otherwise unchanged.

### 3.4 Rewritten: `audit-reports.blade.php`

One `x-filament::section` per repository, replacing the raw-div cards:

- Repo name, audit count, last-run relative time.
- Current overall score and delta from the previous run.
- Trend chart. **Only rendered where a repo has more than one audit** — the current code already
  gates this on `$group['scores']->count() > 1` and that behaviour is kept; single-audit repos
  show a score and no chart.
- Schedule control as a Filament `Select` (off / weekly / monthly) rather than a bare `<select>`,
  still calling `setSchedule()`. Remains **subscription-only** (`$allowance > 0`), because
  scheduled runs consume monthly allowance that free-tier users do not have.
- Per-run history nested inside the section, with Download PDF and View online as
  `x-filament::button` rather than underlined anchors.
- A designed empty state replacing the bare `<p>No audit reports yet.</p>`.

The submit form and its `canRun` / `freeRunsRemaining` logic land in `bdb49fa` and are **not**
re-litigated here; only their presentation changes.

---

## 4. Part B — Public report

### 4.1 New: `ReportPresenter`

`app/Services/AuditReport/ReportPresenter.php`.

`resources/views/reports/partials/deep-findings.blade.php` currently opens with a `@php` block
that owns real domain logic: a `$severityRank` map, grouping findings by file, and a compound
sort (`[-($severityRank[...]), $f['line']]`) that relies on PHP's element-wise array comparison.
`CLAUDE.md` places business logic in services, not templates, and this logic has **no tests
today**.

The presenter takes a `ReportPayload` array and returns ordered, grouped structures ready for
rendering. Both the web and PDF views consume it, so their ordering cannot drift apart — which
is the actual risk in forking the presentation.

### 4.2 Forked partials

`partials/deep-findings.blade.php` and `partials/expert-review.blade.php` are currently
`@include`d by **both** `audit-web.blade.php` and `audit.blade.php`, and they style themselves
with a class vocabulary (`.risk`, `.risk-title`, `.badge-high`) that each parent defines
**separately in its own inline `<style>`**. That duplicated CSS contract is what couples the two
stacks.

They fork into `partials/web/*` (Tailwind utilities) and `partials/pdf/*` (inline styles), both
fed by `ReportPresenter`.

### 4.3 `audit-web.blade.php`

Rewritten against Tailwind, pulled in with `@vite('resources/css/app.css')` — the view is
currently fully standalone and references no bundle.

**Constraint:** `app.css` loads the daisyUI `flexpick` theme with `color-scheme: dark` and
`default: true`. Importing the bundle naively makes the report inherit **dark**, the opposite of
the chosen direction. The report therefore uses **Tailwind utilities plus the
`--color-primary-*` / `--color-secondary-*` custom properties from `colors.css` directly**, sets
its own light surface explicitly, and does **not** use daisyUI semantic classes
(`bg-base-100`, `text-base-content`).

Palette: warm light ground, warm neutral borders, gold for the headline score and primary
actions, coral for regressions and high-severity badges, DM Sans throughout.

### 4.4 `audit.blade.php` (PDF)

Keeps its inline `<style>` and table-based layout. **DomPDF supports neither flexbox nor grid**,
which is why this file is built the way it is; that structure is not to be "modernised".

Its palette and type choices are aligned to the same tokens as the web report — hardcoded, since
DomPDF cannot consume the Vite bundle. Font stays `DejaVu Sans` (DomPDF's embedded font); DM Sans
would require font embedding and is out of scope.

---

## 5. Data flow

Unchanged: `AuditPipeline` → `ReportPayload` → persisted on `AuditReport.payload`.

Changed: the report views no longer read `payload` directly for findings. They call
`ReportPresenter`, which becomes the single place that knows severity ordering and file grouping.

The dashboard reads through `AuditEntitlementService` and `SubscriptionService` as it does today.

Net query cost is close to flat rather than additive: `AuditStatsWidget` currently calls
`subscriptionAllowance()` and `deepAiCredits()` (lines 44–45) to build its quota stats, and both
calls **move** to `PlanUsageWidget` along with the stats themselves. The one genuinely new cost is
`RecentAuditsWidget`'s delta lookup — see §3.3, which must be a single query, not one per row.

---

## 6. Testing

- **`ReportPresenter`** — unit tests for severity ordering, file grouping, and the compound sort.
  This logic is untested today, so extraction is a net coverage gain.
- **PDF regression** — assert `audit.blade.php` renders and `AuditReportService` still produces a
  `pdf_path`. The PDF is a paid deliverable and DomPDF fails in ways a browser does not.
- **`AuditReportControllerTest`** — asserts content strings (`'Fixture summary.'`,
  `'Reviewed thoroughly, no blockers.'`), not markup or classes. Verified: a redesign preserving
  content keeps these green. They are the regression net for Part B.
- **`PlanUsageWidget`** — visibility and content across three states: subscribed, free-tier, and
  no entitlement (`audit.free_reports_limit => 0`).
- **Existing widget and navigation tests** stay green. Note `AuditStatsWidgetTest` asserts on
  quota strings that move to `PlanUsageWidget`; those assertions move with them.
- **Sidebar rendering** — at least one test must render a dashboard page end-to-end. Filament
  throws at render time for navigation misconfiguration (as a group/item icon clash did during
  `bdb49fa`), and `canView()`-style unit assertions do not catch it.

---

## 7. Risks

1. **The PDF is a paid deliverable.** Forking the partials is the change most likely to break it
   silently. Mitigated by the render assertion in §6 and by leaving `audit.blade.php`'s structure
   alone.
2. **`@vite` on a public page.** The report becomes dependent on built assets. Production deploys
   build them; local environments need `npm run build` at least once, as `CLAUDE.md` already
   notes for `/pricing`.
3. **daisyUI dark default** (§4.3) — the trap that would silently undo the chosen light direction.
4. **Widget query cost.** Low, but not zero. The subscription lookups move out of
   `AuditStatsWidget` rather than being duplicated (§5); the new cost is `RecentAuditsWidget`'s
   missing `->with('report')` plus one delta query. Both are called out in §3.3 precisely because
   the current code has no eager loading and would otherwise degrade quietly.

---

## 8. Out of scope, deliberately

- **Progress percentages on running audits.** The pipeline persists discrete statuses
  (`queued`, `analyzing`, `report_ready`), not completion progress. A progress bar would be
  fiction unless `AuditPipeline` gains real progress tracking — backend work, not a UI pass.
- **Consolidating the Audit Reports page with the Audits resource.** They overlap (reports grouped
  by repo vs requests as a flat table), but the decision was to group them under one navigation
  section and leave both in place.
- **The admin panel** (`app/Filament/Admin/**`). Operator-facing, different audience.
- **Emails** (`resources/views/emails/audit/**`). They have the same off-brand problem as the
  report and deserve their own pass.
- **The `Team` navigation group's latent icon clash.** It declares an icon but has no members, so
  it never renders; whoever populates it will hit the constraint from §3.2. Noted, not fixed.
- **Any change to audit pipeline, entitlement rules, or payload contract.**
