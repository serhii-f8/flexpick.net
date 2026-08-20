# Audit Types manual pricing — design

Date: 2026-08-20
Status: approved for planning

## Problem

Audit Types (the `AuditTier` enum: Diagnostic, Automated, Deep AI, Expert)
are moving to manual, outside-the-system invoicing rather than pure
self-serve checkout. Staff need to see what to charge a client at a glance,
Diagnostic needs to stop being a free lifetime-quota tier, and pricing
should no longer be shown to the public.

This is the pricing/labeling slice of a larger set of changes (partner
unlimited plan, a Super Admin Paid/Not Paid status field, admin nav
cleanup, frontend homepage work) tracked separately. This spec covers only
Audit Types pricing and relabeling.

## Decisions

1. **Diagnostic stops being free.** It becomes a real paid tier like the
   other three, priced nominally at **$5**. The free-quota mechanism
   (`AuditEntitlementService::freeRunsLimit/hasFreeRun/consumeFreeRun` and
   the per-user `audit_bonus_free_runs` bonus) is **not removed** — only
   `config('audit.free_reports_limit')` changes from `3` to `0`. The bonus
   escape hatch stays available for manually comping a specific user.

2. **Public lead-capture funnel is unchanged in code.** A visitor still
   submits repo + email with no login or payment
   (`AuditRequestController::store` → `AuditRequestService::submit`, no
   payment step added there). `AuditRequestService::routeVerified()`
   already branches to `AWAITING_PAYMENT` + sends `AuditQuotaExhausted`
   (which includes the buy-now link) whenever `hasFreeRun()` is false. With
   `free_reports_limit = 0`, every new Diagnostic request takes this branch
   by default, which is exactly the desired "capture the lead, require
   payment before the pipeline runs" behavior. No changes needed to
   `AuditRequestController`, `AuditRequestService::routeVerified()`,
   `HandleAuditTierOrder`, or the dashboard's existing tier-purchase flow
   (`AuditReports.php`) — all of them already treat tier price generically
   via `config('pricing.tiers')` / `TierQuota::purchasable()`.

3. **Diagnostic renamed** from "Free Diagnostic" to "Diagnostic Report" —
   both `AuditTier::label()` and the `config/pricing.php` product `name`.

4. **Price-aware label is a new, separate method** —
   `AuditTier::labelWithPrice(): string` (e.g. `"Automated Health Report —
   $49"`) — rather than changing `AuditTier::label()` itself. `label()`
   keeps returning the plain name because it's used in places where a price
   suffix reads badly (the `AuditCostWidget` internal-spend column, the "No
   :tier runs left" notification, tier badges on report views) or would
   duplicate a price already shown separately (the dashboard's tier-picker
   in `audit-reports.blade.php`, which shows `$quota->priceCents` via its
   own "Buy for $X" line).

5. **`labelWithPrice()` is used in exactly two resources**: the Admin
   `AuditRequestResource` (`app/Filament/Admin/Resources/AuditRequests/`)
   tier `Select` on the create/edit form, and its table's tier column; and
   the Dashboard `AuditRequestResource`
   (`app/Filament/Dashboard/Resources/AuditRequests/`) table's tier column.
   These are the two places staff/customers see an audit *type* as a
   standalone value needing a price for reference.

6. **Single source of truth for tier price.** `AuditTier` gains
   `priceCents(): ?int`, doing the `config('pricing.tiers')` lookup that
   `AuditEntitlementService::tierPriceCents()` currently does inline.
   `AuditEntitlementService::tierPriceCents()` becomes a thin delegate to
   `AuditTier::priceCents()`. `labelWithPrice()` is built from `label()` +
   `priceCents()`.

7. **Public `/pricing` route gated behind auth.** `/pricing`
   (`resources/views/pricing.blade.php`, the SaaSykit subscription-plan
   page via `<x-plans.all>` — a different concept from Audit Types, but
   requested in this pass) gets wrapped in the `auth` middleware group in
   `routes/web.php`. Guests are redirected to `/login`; logged-in users see
   it unchanged. Note this route currently sits alongside a
   `Route::get('/pricing', ...)` redirect target used elsewhere
   (`return redirect()->route('pricing')`, line 45) — that redirect must
   still resolve correctly for an authenticated caller; for a guest it will
   now bounce through login, which is the intended behavior.

## Out of scope (tracked separately)

- Partner unlimited plan (new `Plan`/`Product` entry).
- Super Admin Paid/Not Paid status field on `AuditRequest`.
- Admin nav reordering / hiding Orders, Subscriptions, Payments.
- Frontend homepage Pickvy booklets + layout cleanup.
- The frontend marketing homepage's own inline pricing display
  (`frontend/src/pages/index.astro`, reads `pricing.json`) is untouched by
  this spec — it was not named in the "hide pricing" request, which was
  specifically about the backend `/pricing` route.

## Files touched

- `config/pricing.php` — add `audit-diagnostic` tier entry.
- `config/audit.php` — `free_reports_limit` 3 → 0.
- `app/Constants/AuditTier.php` — rename Diagnostic label, add
  `priceCents()` and `labelWithPrice()`.
- `app/Services/AuditReport/AuditEntitlementService.php` —
  `tierPriceCents()` delegates to `AuditTier::priceCents()`.
- `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php` —
  use `labelWithPrice()` in the tier `Select` and table column.
- `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`
  — use `labelWithPrice()` in the table column.
- `routes/web.php` — gate `/pricing` behind `auth`.
- `database/seeders/AuditMonetizationSeeder.php` — no code change; reruns
  to pick up the new config entry.

## Testing

- Existing `AuditMonetizationSeederTest` should extend to assert the
  `audit-diagnostic` product is seeded with the right price.
- `AuditEntitlementServiceTest` (or equivalent): `hasFreeRun()` is false by
  default post-change; `quotaFor(DIAGNOSTIC)` is `purchasable()`.
- Feature test: submitting a new Diagnostic request via
  `POST /audit-requests` → verify → lands in `AWAITING_PAYMENT`, not
  `QUEUED`.
- Feature test: guest `GET /pricing` redirects to login; authenticated
  `GET /pricing` succeeds.
- Filament resource tests (if present) for both `AuditRequestResource`
  tier columns rendering price.
