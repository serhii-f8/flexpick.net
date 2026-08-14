# Audit Tier Selection and Per-Tier Metering

**Date:** 2026-08-14
**Spec references:** F5.12.1 (tier attribute), F5.12.5 (catalog and entitlement rework), §19 Q34
**Depends on:** Phase 11's tier attribute and catalog, Phase 12's deep-review stage, Phase 13's
expert delivery hold

---

## 1. Scope

The three tiered pipelines already exist. Phases 11–13 shipped `AuditTier`, per-tier profiles
resolved from `config/audit.php`, the deep-review stage, the expert delivery hold, the reviewer
queue, the three one-time products, and per-tier entitlement counters. What does not exist is any
way for a customer or an operator to *choose* a tier, and the absence has left the metering wired
to a tier nothing writes.

This design delivers:

1. A tier selector on the dashboard audit page, and a tier field in the operator panel.
2. Correct per-tier metering, including a fix for the two paths that currently authorize a run
   against one tier's quota and then create it at another.
3. Expert audits as a metered tier (`audit_expert_credits`), alongside the existing
   `audit_analyses_per_month` and `audit_deep_ai_credits`.
4. A one-time-purchase fallback when the selected tier has no remaining quota.
5. Per-schedule tier selection for recurring audits.

### The defect this fixes

`AuditRequest` defaults `tier` to `diagnostic` (`app/Models/AuditRequest.php:65-67`, mirroring the
migration default). Only `HandleAuditTierOrder` ever writes a different value. Every other entry
point — the dashboard page, the guest funnel, the scheduler, the admin launch and retry actions —
produces a `diagnostic` run.

For the guest funnel that is correct: diagnostic *is* the free acquisition step. For the two
dashboard-source paths it is a live billing defect with two independent consequences:

| Path | Line | Consequence |
| --- | --- | --- |
| `Filament/Dashboard/Pages/AuditReports::launchAudit()` | 73-81 | Creates an untiered request after gating on `remainingDashboardRuns()` |
| `Console/Commands/RunScheduledAudits` | 33-39 | Same shape: checks the allowance, then creates an untiered request |

Because `runsThisMonth()` filters `where('tier', 'automated')`
(`AuditEntitlementService.php:81-89`) and these rows are `diagnostic`, the count is permanently
zero. Therefore:

- **The subscription allowance is never consumed.** `remainingDashboardRuns()` always returns the
  full plan allowance, so a subscriber can run unlimited audits.
- **Subscribers receive the wrong product.** A $59–$1,500/month customer's runs execute the
  `diagnostic` profile — three scanners and a 4,000-token budget — rather than the `automated`
  profile they pay for, which runs five scanners at 16,000 tokens and narrates twelve groups
  (`config/audit.php:30-80`).

Introducing tier selection necessarily repairs both, because the selector is what makes the tier
explicit at creation.

### Out of scope, deliberately

- **Changes to any tier's pipeline internals** — scanner sets, token budgets, prompt templates,
  and the deep-review and expert stages are unchanged. This design alters which tier is recorded,
  never what a tier does.
- **Tier selection in the public guest funnel.** The anonymous intake stays diagnostic-only; it is
  the free acquisition step, and offering paid tiers before an account exists is a conversion
  question, not this one.
- **Per-order custom pricing**, unchanged from the Phase 13 decision. The `Discount`/`DiscountCode`
  system remains the manual lever.
- **Billing-anchor-aligned metering.** The calendar-month window (`now()->startOfMonth()`) stands.
  Aligning quotas to each tenant's subscription anchor is a separate change affecting every plan,
  not only audits.
- **Reviewer staffing and a published expert-review SLA** — Q34 stays open, as Phase 13 recorded.
  See risk R2.
- **A dedicated usage ledger.** `audit_requests` already is the ledger; §2 D1 records why the
  unused `SubscriptionUsage`/`PlanMeter` models stay unused.

---

## 2. Decisions

### D1 — Tier-keyed quota lookup, not a third hardcoded pair

`AuditEntitlementService` carries two hardcoded pairs today: `subscriptionAllowance()` with
`dashboardRunsUsedThisMonth()`, and `deepAiCredits()` with `deepAiRunsUsedThisMonth()`. Adding
expert as a third pair yields nine public methods and forces every one of eleven call sites to
branch on tier to pick the matching pair.

That shape is what produced the defect. `remainingDashboardRuns()` gates a run that is then created
at a tier the method does not meter — a mismatch that is only possible because the authorizing
quota and the created tier are named independently. A single generic lookup keyed by
`AuditTier` makes the two the same value by construction.

A dedicated usage table was considered and rejected. It would be correct if quotas needed to
survive request deletion or align to a billing anchor, but neither is a requirement, and it
duplicates a ledger that already works.

### D2 — An explicit `funding` column, not inferred metering

Metering currently infers "this run consumed allowance" from a combination of `source`, `free_run`,
and `prepaid`. The purchase fallback (§5) breaks that inference: between checkout intent and
payment, a request is `deep_ai`, `source='dashboard'`, `prepaid=false`, and would be counted
against the subscription credit the customer is paying cash to avoid using.

Stacking guards — `prepaid = false AND free_run = false AND status != awaiting_payment` — would
work today and silently rot the first time a status is added. Recording how a run was funded at the
moment it is created makes metering a single condition over a recorded fact rather than a
reconstruction from three proxies. It is also the most direct reading of the requirement that each
audit type be tracked separately.

### D3 — Purchased runs never consume subscription quota

A customer who pays $199 for a Deep AI audit must not also lose a Deep AI credit. This follows from
D2 — a purchased run is `funding='purchase'` and metering counts only `funding='allowance'` — but
it is recorded as its own decision because it is a money-correctness invariant, and because
`HandleAuditTierOrder` presently sets `free_run => false` without setting `prepaid`, so today's
purchased runs are indistinguishable from allowance-funded ones by column value.

Setting `prepaid` on tier orders has a second, desirable effect: `AuditReportService::create()`
already auto-unlocks a prepaid report (line 41), which is the intended behavior for a paid run and
was previously reached only via the `source='dashboard'` arm of the same condition.

### D4 — The selected tier is re-authorized server-side

`$tier` is a public Livewire property and therefore client-controlled. A user can set it to
`expert` regardless of what the rendered UI offered. `launchAudit()` re-resolves the quota and
refuses to create the run when it is exhausted. The rendered selector communicates state; it is
never the gate.

### D5 — Diagnostic is a visible, selectable tier funded by the free quota

Today's dashboard spends free runs on a diagnostic pipeline without ever naming it, so a user
cannot tell what they received. Showing "Free Diagnostic" as a fourth option makes the existing
behavior honest and keeps the free quota off the $49 Automated tier — giving that away three times
per email would undercut the tier it is meant to sell.

The free quota keeps its current semantics: lifetime, keyed by email, `config('audit.free_reports_limit')`
plus the `audit_bonus_free_runs` referral bonus. Only its rendering changes.

### D6 — Expert becomes a metered tier, seeded at zero credits

`audit_expert_credits` is added to plan metadata so Expert is a first-class metered tier rather
than a purchase-only special case, and so a custom Enterprise deal is an admin metadata edit rather
than a code change.

All four plans seed it at **0**. Agency at $499/month cannot absorb a $999 audit, and Enterprise at
$1,500/month including one leaves almost no margin. Seeding zero keeps Expert a deliberate purchase
while the mechanism exists for the first deal that warrants it.

### D7 — Scheduled audits carry their own tier

`audit_schedules` gains a `tier` column rather than hardcoding automated. A schedule whose tier has
no remaining quota that month is **skipped and logged** — never downgraded to a cheaper tier, and
never auto-charged. A silent downgrade would deliver a lesser product without disclosure, which is
the failure mode Phase 12's D1 was written to prevent; an automatic charge is a payment the
customer did not authorize at schedule time.

### D8 — Expert-tier runs become visible in the dashboard list

`AuditReports::getViewData()` currently excludes `expert_review` reports (line 130). With Expert
launchable from the dashboard, that would leave a customer who just spent $999 looking at an empty
list. They render in an "In expert review" state with no download action.

---

## 3. Data model and configuration

### Migrations

Two, both additive:

| Table | Column | Definition |
| --- | --- | --- |
| `audit_schedules` | `tier` | string, default `'automated'`, indexed |
| `audit_requests` | `funding` | string, default `'allowance'`, indexed |

`audit_requests.tier` already exists with the correct default and index
(`2026_08_02_000001_add_tier_to_audit_requests_table`). **No tier backfill.** Historical dashboard
rows genuinely executed the diagnostic profile; relabelling them as automated would falsify the
record and corrupt any cost-per-tier analysis built on the Phase 11 telemetry columns.

`funding` backfills in precedence order: `prepaid` → `purchase`; else `free_run` → `free`; else
`allowance`. Because metering is scoped to the current calendar month, mislabelled historical rows
can only affect the month in which the migration runs, and only for rows that were already
uncounted.

### `funding` values

| Value | Set by | Metered |
| --- | --- | --- |
| `allowance` | Dashboard launch, scheduled runs | Yes — counts against the tier's monthly quota |
| `free` | Guest funnel, dashboard diagnostic runs | No — counted separately, lifetime, by email |
| `purchase` | Dashboard checkout intent (§5.3), `HandleAuditTierOrder`, `HandleAuditUnlockOrder` | No |

`free_run` is retained unchanged. It is indexed and drives the lifetime-by-email free count, which
is a different question from monthly metering; collapsing the two would conflate a lifetime quota
with a recurring one.

### Pricing catalog

`config/pricing.php` subscriptions gain `audit_expert_credits`, seeded at 0 for Starter, Growth,
Agency, and Enterprise (D6). This propagates to three places that must land in the same change:

- `AuditMonetizationSeeder::seedSubscriptions()` — the product `metadata` array (lines 67-70).
- `ExportPricingCommand` — adds the key to `frontend/src/data/pricing.json`.
- The marketing pricing grid that renders that file.

`app:export-pricing --check` is a CI gate asserting backend configuration and the exported
marketing data match, so a backend-only change fails the build.

Plan metadata continues to live in the `products.metadata` JSON column, edited in the admin through
the existing free-form `KeyValue` field (`ProductResource.php:79-83`).

---

## 4. The entitlement core

### Shape

```php
private const QUOTA_KEYS = [
    AuditTier::AUTOMATED->value => 'audit_analyses_per_month',
    AuditTier::DEEP_AI->value   => 'audit_deep_ai_credits',
    AuditTier::EXPERT->value    => 'audit_expert_credits',
];

public function allowance(Tenant $tenant, AuditTier $tier): int;
public function runsUsedThisMonth(User $user, AuditTier $tier): int;
public function remainingRuns(User $user, Tenant $tenant, AuditTier $tier): int;
public function quotaFor(User $user, ?Tenant $tenant, AuditTier $tier): TierQuota;
```

`allowance()` keeps the existing `planMetadata()` behavior — the maximum of the keyed value across
all active tenant subscriptions.

`AuditTier::DIAGNOSTIC` has no entry in `QUOTA_KEYS`. Both `remainingRuns()` and `quotaFor()`
resolve it against the lifetime free quota instead — `freeRunsLimit($user->email)` minus
`freeRunsUsed($user->email)`. Absorbing the lifetime-versus-monthly difference inside these two
methods is the point: callers ask for a tier's remaining runs and never branch on which kind of
quota backs it.

### `TierQuota`

A `final readonly` value object: `tier`, `label`, `limit`, `used`, `remaining`, `isLifetime`,
`priceCents`, `purchasable`. It is what the selector and `PlanUsageWidget` render, so both become a
loop over tiers rather than a fixed set of branches.

`priceCents` and `purchasable` are read from `config('pricing.tiers.*')`, keyed by the slug whose
`tier` value matches — keeping every monetary figure in the single source of truth that config file
already declares itself to be. Diagnostic has no entry there, so it resolves to `purchasable =
false` with a null price, which is what makes it the one tier that can only be refused rather than
routed to checkout when its quota is spent.

### Metering

```php
AuditRequest::query()
    ->where('user_id', $user->id)
    ->where('funding', 'allowance')
    ->where('tier', $tier->value)
    ->where('created_at', '>=', now()->startOfMonth())
    ->count();
```

The `source = 'dashboard'` filter is replaced by `funding = 'allowance'`. It was always a proxy for
"this run came out of the plan," and it was the wrong proxy: it admitted the untiered scheduler
rows and, under the purchase fallback, would have admitted unpaid checkout intents.

### Access gate

`hasAuditAccess()` generalizes its subscription arm from `subscriptionAllowance($tenant) > 0` to
"any tier has a nonzero allowance," so a tenant holding only Expert credits is not locked out of
the audit navigation. The prior-request and free-run arms are unchanged.

---

## 5. Selection surfaces

### Dashboard page

`AuditReports` gains `public string $tier`, defaulted in `mount()` to the first tier with remaining
quota and falling back to `diagnostic`. `getViewData()` returns a `TierQuota` per tier; the blade
renders a radio group over them, each showing its remaining quota, or its price when exhausted and
purchasable.

`launchAudit()` re-resolves the quota server-side (D4). Three outcomes:

| Condition | Outcome |
| --- | --- |
| Quota remaining | Create at the selected tier, `funding = 'allowance'` (or `'free'` for diagnostic) |
| Exhausted, purchasable | Enter the purchase flow (§5.3) |
| Exhausted, not purchasable | Refuse with a notification; no request is created |

The per-repository re-run action (`audit-reports.blade.php:75-77`) currently calls
`launchAudit('{repoUrl}')` with no tier. It passes the originating request's tier, so re-running a
Deep AI audit does not silently downgrade to Automated.

Expert-tier rows render in the list per D8.

### Operator panel

`Admin/Resources/AuditRequests/AuditRequestResource::form()` gains `Select::make('tier')` over
`AuditTier::cases()`. Tier is already shown in the infolist (line 100) but is not editable, so an
operator cannot presently re-run a request at a corrected tier — the natural remedy when a purchase
or a selection lands wrong.

No new action is needed: the existing `retry` action re-runs at the record's stored tier, so
editing the tier and retrying is the complete workflow.

The `ExpertReviewResource` queue is unchanged; it already scopes to `tier = expert AND status =
expert_review`.

### Purchase fallback

`HandleAuditTierOrder` today locates the buyer's most recent `diagnostic` request and clones it.
That serves the pricing-page funnel, where no repository was named at purchase time, but it discards
the repository URL and tier a dashboard user just selected.

The flow becomes:

1. `launchAudit()` creates the request at the selected tier with status `awaiting_payment` and
   `funding = 'purchase'`.
2. It writes a `UserParameter` intent holding that request's uuid, mirroring the existing
   `audit_unlock_intent` / `audit_run_intent` pattern in `HandleAuditUnlockOrder`.
3. It redirects to `buy.product` for the tier's slug from `config('pricing.tiers')`.
4. On the order event, `HandleAuditTierOrder` prefers the intent: it flips that request to `queued`,
   sets `prepaid = true`, and dispatches `GenerateAuditReport`.
5. With no intent present, the listener falls back to today's latest-diagnostic behavior, so the
   existing pricing-page funnel is untouched.

`awaiting_payment` is an existing member of `AuditRequestStatus`, already handled by the admin
panel's `launch` action and by the status display mapper, so no status plumbing is added.

An abandoned checkout leaves an `awaiting_payment` request behind. These are harmless — they are
`funding = 'purchase'` and therefore unmetered, and the status already renders as a pending state —
but they accumulate. `app:purge-unverified-audit-requests` currently targets unverified requests
only; extending it to sweep `awaiting_payment` rows older than `config('audit.unverified_purge_days')`
is in scope for this change, since this is the first path that creates them in volume.

### Scheduling

`RunScheduledAudits` reads `$schedule->tier`, checks `remainingRuns($user, $tenant, $tier)`, and
creates the request at that tier with `funding = 'allowance'`. Skips gain a log line — a schedule
that quietly stops firing is otherwise invisible to both operator and customer.

The blade's schedule control gains a tier select alongside off/weekly/monthly, and
`AuditReports::setSchedule()` takes the tier as a third argument.

---

## 6. Call sites

Eleven call sites reference `AuditEntitlementService`. Four use only `hasAuditAccess()` and need no
change beyond its generalized subscription arm: `AuditStatsWidget:79`, `RecentAuditsWidget:110`,
`Dashboard.php:28`, and the dashboard `AuditRequestResource:62`.

| File | Change |
| --- | --- |
| `Filament/Dashboard/Pages/AuditReports` | Tier property, per-tier quotas in view data, re-authorization in `launchAudit()`, tier on `setSchedule()` |
| `Filament/Dashboard/Widgets/PlanUsageWidget` | One usage bar per tier with a nonzero allowance, replacing the single hardcoded bar |
| `Console/Commands/RunScheduledAudits` | Per-schedule tier; logged skips |
| `Filament/Admin/Resources/AuditRequests/AuditRequestResource` | Tier select in the form; `funding` set by the `launch` action |
| `Services/AuditRequestService` | Sets `funding = 'free'`; tier stays diagnostic — behavior unchanged |
| `Listeners/Order/HandleAuditTierOrder` | Intent preference, `prepaid = true`, `funding = 'purchase'` |
| `Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests` | See below |
| `Console/Commands/PurgeUnverifiedAuditRequests` | Second sweep for abandoned `awaiting_payment` rows past the retention window |

The purge command presently deletes only `pending_verification` rows with a null
`email_verified_at`. The abandoned-checkout sweep is a separate `where` over `awaiting_payment`
against the same `unverified_purge_days` window, not a widening of the existing one — a dashboard
user's abandoned intent is email-verified, so folding it into the existing condition would delete
nothing. The command's name becomes marginally inaccurate; renaming it would break the
`routes/console.php` schedule entry and any operator runbook reference, so it stays.

`ListAuditRequests:26` shows its `runNewAudit` action only when `remainingDashboardRuns > 0`,
ignoring free runs entirely — so a free-quota user sees the action on the audit page but not on the
resource. It moves to the same any-tier-has-quota test the page uses. This is a pre-existing
inconsistency, fixed here because the generalized API makes the two tests literally the same
expression.

---

## 7. Testing

PHPUnit, `TestCase`-based, scaffolded with `php artisan make:test --phpunit`. The Pest snippets in
`backend/AGENTS.md` do not apply.

### Existing tests requiring rework

`AuditEntitlementServiceTest`, `AuditSubscriptionEntitlementTest` (generic quota API),
`AuditMonetizationSeederTest` (expert-credits metadata), `PlanUsageWidgetTest` (per-tier bars).
`HandleAuditUnlockOrderTest` and `AuditReportUnlockTest` need review for the `funding` column.

### New coverage, in priority order

1. **A dashboard Automated run consumes exactly one allowance unit.** The regression test for the
   defect in §1 — assert the created request's tier and that `remainingRuns()` decrements.
2. **An unauthorized tier is refused server-side.** Set the Livewire property directly to a tier
   with zero quota and assert no request is created (D4).
3. **A purchased run does not decrement subscription quota** (D3) — `funding='purchase'` is not
   counted.
4. **A checkout intent pending payment is not metered** — the case that motivated D2.
5. **A scheduled audit runs at its schedule's tier and debits that tier**; an exhausted quota skips
   and logs rather than downgrading (D7).
6. **An expert-tier dashboard run holds at `expert_review` and appears in the dashboard list** (D8).
7. **`app:export-pricing --check` passes** with the new metadata key.

### Execution

Tests run inside Docker (`docker compose exec laravel.test php artisan test`), from the repository
root. The full suite exceeds a single agent timeout, so implementation uses targeted `--filter`
runs and reserves the full suite for checkpoints. **One test command at a time** — concurrent runs
collide on the test database.

---

## 8. Risks

| # | Risk | Mitigation |
| --- | --- | --- |
| R1 | Marketing pricing drifts from backend configuration | `app:export-pricing --check` already gates CI; backend and frontend land together |
| R2 | One-click Expert from the dashboard starts an `expert_review_sla_hours` clock while reviewer staffing (Q34) is unresolved | D6's zero seeded credits keep Expert a deliberate purchase rather than something a subscriber trips into; `scopeBreachingExpertReviewSla()` already exists to surface breaches |
| R3 | `funding` backfill mislabels historical rows | Bounded by the calendar-month metering window; only the migration month is affected, and only for rows that were previously uncounted |
| R4 | Subscribers on plans with zero Expert credits see a permanently priced-out option | Intended — it is the upsell surface, and D6 records why the credits are zero |
| R5 | The generalized entitlement API touches eleven call sites in one change | All are covered by existing tests; the four `hasAuditAccess()` sites are signature-stable |

---

## 9. Exit criteria

1. A dashboard user can select Free Diagnostic, Automated, Deep AI, or Expert, and the created
   `AuditRequest` carries that tier.
2. An Automated dashboard run decrements `audit_analyses_per_month`; a Deep AI run decrements
   `audit_deep_ai_credits`; an Expert run decrements `audit_expert_credits`. Each is independent.
3. A run created from a purchase decrements nothing.
4. Selecting a tier with no remaining quota either routes to that tier's checkout or is refused —
   never silently downgraded, and never created.
5. A scheduled audit runs at its configured tier and debits that tier; an exhausted quota logs a
   skip.
6. An operator can change a request's tier and re-run it.
7. All three CI gates pass: `php artisan test`, `vendor/bin/phpstan analyse`,
   `vendor/bin/pint --test`.
