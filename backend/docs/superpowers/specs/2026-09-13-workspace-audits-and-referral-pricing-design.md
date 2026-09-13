# Workspace-Owned Audits, Referral → Pricing, FlexPick Branding

Status: Approved (design), ready for implementation planning
Date: 2026-09-13
Branch: `growth-retention`
Builds on: `2026-09-07-partner-catalog-restriction-design.md` §2 (which
noted `/pricing` was login-only — that is reversed here, see §B.1).

Three independent deliverables. §A is architectural; §B and §C are bounded
changes recorded here so one implementation plan can cover all three. They
ship as three separate commits, in the order B, C, A.

---

## A. Workspace-owned audits and quotas

### A.1 Goal

An audit belongs to the **workspace** (`Tenant`) it was run for, not to
the person who clicked the button. Every member of a workspace sees the
same audit history and draws from the same quota — plan allowance,
purchased one-time credits, and the lifetime free-run quota — metered on
the workspace. A user who belongs to two workspaces sees and spends each
workspace's audits and quota separately, depending on which workspace is
selected in the dashboard.

### A.2 Current state (what changes)

| Today (user-keyed) | Tomorrow (workspace-keyed) |
|---|---|
| `audit_requests.user_id` + `email` decide ownership (`scopeForUser`) | `audit_requests.tenant_id` decides ownership (`scopeForTenant`); `user_id` is kept as *requested by* |
| `runsUsedThisMonth(User)` counts `user_id` | counts `tenant_id` |
| Free runs: `free_run=true` rows by `email`; bonus in `UserParameter` | rows by `tenant_id`; bonus in `TenantParameter` |
| Purchased credits in `UserParameter audit_purchased_credits_{tier}` | `TenantParameter`, same key |
| Checkout intent uuid in `UserParameter audit_checkout_intent` | `TenantParameter`, same key |
| `HandleAuditTierOrder` grants credit / finds source by `order->user_id` | by `order->tenant` (orders already carry `tenant_id`) |
| Report `show`/`download`: `report->user_id === auth()->id()` | member of the request's tenant (see A.6) |

Already tenant-keyed and unchanged: the subscription allowance
(`allowance(Tenant, tier)` reads plan metadata), `AuditSchedule.tenant_id`,
and `Filament::getTenant()` resolution in the dashboard.

### A.3 Data model

**Migration `add_tenant_id_to_audit_requests_table`**
- `tenant_id` unsignedBigInteger nullable, FK → `tenants.id`, `nullOnDelete()`.
- Index `(tenant_id, funding, tier, created_at)` — the monthly meter query.
- Data step (same migration, runs after the column exists), for every row
  with `tenant_id IS NULL`:
  1. Resolve the user: `user_id`, else `users.email = audit_requests.email`.
  2. Resolve the tenant: the tenant with `created_by = user.id` (earliest
     by id), else the user's earliest `tenant_user` membership.
  3. Set `tenant_id`; rows with no user or no tenant stay `NULL` and are
     claimed later by A.4.
- `down()` drops the column only. Data is not restored.

**Migration `create_tenant_parameters_table`** + model `TenantParameter`
- Mirrors `user_parameters`: `id`, `tenant_id` FK cascadeOnDelete, `name`,
  `value` (string), timestamps, unique `(tenant_id, name)`.
- `Tenant::parameters()` hasMany.
- Data step: for each `user_parameters` row named
  `audit_purchased_credits_*` or `audit_bonus_free_runs`, resolve the
  user's tenant with the same rule as above and **add** the integer value
  to the tenant's parameter of the same name (two users mapping to one
  tenant sum). Rows that resolve are deleted from `user_parameters`; rows
  that don't resolve are left in place (harmless, no longer read).
  `audit_checkout_intent` rows are deleted outright — an in-flight checkout
  across a deploy is not worth carrying.

**Model changes**
- `AuditRequest::tenant()` belongsTo; `Tenant::auditRequests()` hasMany.
- `AuditRequest::scopeForTenant(Builder, Tenant)` = `where tenant_id`.
- `AuditRequest::scopeForUser` is **deleted**. Every caller moves to
  `forTenant` (dashboard) or to an explicit `user_id`/`email` query where
  the flow is genuinely personal (A.5). Deleting it makes the compiler /
  test suite find every stale caller.
- `AuditReport` keeps `user_id` (set at creation from the request) — it is
  used by the guest unlock flow (A.6) and as an audit trail.

### A.4 Claiming pre-signup audits

Landing-page submissions (`AuditRequestService::create`) are anonymous and
stay `tenant_id = NULL`. They are attached to a workspace exactly once:

- Listener `App\Listeners\Tenant\ClaimAuditRequestsForTenant`, registered
  for `TenantCreated($tenant, $user)` and `UserJoinedTenant($tenant, $user)`:
  ```
  AuditRequest::whereNull('tenant_id')
      ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))
      ->update(['tenant_id' => $tenant->id]);
  ```
  Email match is case-insensitive (`whereRaw('LOWER(email) = ?')`) to match
  `AuditReportController::unlock()`.
- Because a claimed row keeps `free_run = true`, its free run now counts
  against the workspace (A.5). This is intended: the free quota the person
  already used follows them into their first workspace.
- A row is never re-homed. A user who joins a second workspace brings
  nothing with them.
- The guest → account path (`AuditGuestAccountService` via
  `AuditReportController::unlock()`) creates the user and their tenant
  through `TenantCreationService`, which dispatches `TenantCreated`, so the
  claim happens with no extra wiring. The plan verifies this with a test.

### A.5 Entitlements — `AuditEntitlementService`

All dashboard-facing methods take `Tenant $tenant` (non-nullable) instead
of `User $user, ?Tenant $tenant`. The dashboard always has a tenant.

| Method | Keyed on |
|---|---|
| `freeRunsLimit(Tenant)` | `config('audit.free_reports_limit')` + `TenantParameter audit_bonus_free_runs` |
| `freeRunsUsed(Tenant)` | `AuditRequest forTenant where free_run = true` |
| `hasFreeRun(Tenant)` | the two above |
| `runsUsedThisMonth(Tenant, tier)` | `forTenant where funding = allowance, tier, created_at >= month start` |
| `purchasedCreditBalance / grantPurchasedCredit / spendPurchasedCredit(Tenant, tier)` | `TenantParameter audit_purchased_credits_{tier}` |
| `quotaFor / remainingRuns / quotas / consume(Tenant, …)` | composed from the above; `allowance(Tenant, tier)` unchanged |
| `hasAuditAccess(Tenant)` | `forTenant()->exists()` ‖ `hasFreeRun(Tenant)` ‖ any allowance > 0 ‖ any purchasable tier |

The **anonymous funnel** keeps email-keyed free-run accounting, renamed so
the two cannot be confused: `freeRunsLimitForEmail(string)`,
`freeRunsUsedForEmail(string)`, `hasFreeRunForEmail(string)`. The email
variants read no bonus (there is no tenant yet). `consumeFreeRun(AuditRequest)`
is unchanged. `AuditRequestService` (landing page) and
`AuditGuestAccountService` are the only callers of the email variants.

`TierQuota` value object is unchanged.

### A.6 Creation sites, listeners, commands

Every place that creates an `AuditRequest` sets `tenant_id`:

- `AuditReports::launchAudit()` and `::purchase()` — `Filament::getTenant()->id`.
  `purchase()` stores the intent uuid in `TenantParameter audit_checkout_intent`.
- `RunScheduledAudits` — `$schedule->tenant_id`.
- `HandleAuditTierOrder` — `$order->tenant_id`; the intent lookup reads the
  tenant parameter; the awaiting-payment fallback and `sourceRequestFor()`
  use `forTenant($order->tenant)`; `grantPurchasedCredit()` grants to
  `$order->tenant`. If `$order->tenant` is null (should not happen for a
  one-time product order, but the column is nullable) the listener logs and
  returns, matching its existing null-user behaviour.
- `HandleAuditUnlockOrder`, `SendAuditUnlockReminders` — the unlock flow is
  personal (a guest pays to see their own report), keyed on
  `order->user_id` / request `user_id`. Unchanged, except that a request
  created by `SendAuditUnlockReminders` copies `tenant_id` from the source
  request.
- `AuditRequestService::create` (landing page) — `tenant_id` stays null.

### A.7 Dashboard and report access

- `AuditRequestResource::getEloquentQuery()`, `RecentAuditsWidget`,
  `LatestHealthWidget`, `AuditStatsWidget`, `PlanUsageWidget`,
  `Dashboard`, `ListAuditRequests` — `forTenant(Filament::getTenant())` and
  the new entitlement signatures. `$isScopedToTenant` stays `false` (the
  resource scopes explicitly through `forTenant`, which is what the widgets
  share).
- `AuditRequestResource` list gains a **Requested by** column
  (`user.name`, falls back to the request `name`).
- `AuditReportController::show()` and `download()` authorise when any of:
  1. `auth()->user()->isAdmin()`;
  2. the request's `tenant_id` is in `auth()->user()->tenants()->pluck('id')`;
  3. the request is tenantless and (`report->user_id === auth()->id()` or
     request email matches the user's email, case-insensitive) — today's
     rule, kept only for unclaimed rows.
  Extracted to `AuditReport::isViewableBy(User): bool` so the two actions
  and any future Filament action share it. Signed report URLs are unchanged.
- `AuditReportController::unlock()` is unchanged (personal flow).
- Emails: still sent to the requester's email only. Fan-out to workspace
  members is out of scope.

### A.8 Admin panel

- `AuditRequestResource` (admin) shows the tenant name as a column and
  filter. No other admin change.
- Bonus free runs and purchased credits are granted on the tenant. There is
  no admin UI for either today (they are set by hand); none is added.

### A.9 Testing

Unit (`tests/Unit/Services/AuditReport/AuditEntitlementServiceTest` and
siblings, rewritten to the new signatures):
- Two members of one tenant share allowance, free runs and purchased
  credits: member A spends, member B sees the reduced quota.
- One user in two tenants: runs in tenant 1 do not reduce tenant 2's quota.
- Email variants keep working for the anonymous funnel.
- `ClaimAuditRequestsForTenant`: claims by `user_id` and by email
  (case-insensitive) on `TenantCreated` and `UserJoinedTenant`; never
  re-homes an already-claimed row.
- Migration data step: seeded legacy rows land on the creator's tenant,
  else earliest membership, else stay null; user parameter credits are
  summed onto the tenant.

Feature:
- Dashboard list shows a teammate's audit; widgets count it.
- Report `show`/`download`: 200 for a teammate, 403 for a member of another
  tenant, 200 for admin.
- `HandleAuditTierOrder` grants credit to the order's tenant; a subsequent
  launch by another member of that tenant spends it.
- `RunScheduledAudits` creates the run with the schedule's tenant.
- Guest unlock → account creation claims the report's request into the new
  tenant.
- Existing tests that call `forUser` or pass `$user` into the entitlement
  API are updated, not deleted.

---

## B. Referral link → pricing page, Sign Up button

### B.1 Changes

1. `ReferralService::getReferralLink()` returns
   `url()->query(route('pricing'), ['rc' => $code])` instead of `/?rc=`.
   `TrackReferralCode` is global web middleware, so the code is captured on
   `/pricing` exactly as on `/`.
2. `/pricing` loses `->middleware('auth')` and is public for everyone.
   (`2026-09-07-partner-catalog-restriction-design.md` §2 relied on it
   being gated; the partner catalog filter already handles anonymous
   visitors via `purchasableFor(?User)` and the `fp_rc` cookie, so nothing
   else changes.)
3. `pricing.blade.php`: the guest block under the page header becomes a
   primary **Sign up** button (`route('register')`) with a secondary
   "Already have an account? Log in" link. Signed-in users see nothing there.
4. "Select a plan first": plan and product CTAs already lead to checkout,
   which registers a guest inline. `CheckoutForm::registerUser()` now calls
   `RegisterValidator::validate(..., inviteOnly: true)`, and the checkout
   registration partial renders `<x-auth.invitation-code-field>` when
   `ReferralRegistrationGate::requiresCodeInput()` is true — the same field
   the register page uses. A referral visitor carries the session/cookie
   code and never sees the field.

### B.2 Testing
- `ReferralServiceTest`: link points at `/pricing?rc=CODE`.
- `PricingPageTest`: guest gets 200 and sees the Sign up button; signed-in
  user does not see it. Remove `test_guest_is_redirected_to_login`; update
  `LayoutBrandingTest` likewise.
- Checkout: with `REFERRAL_ONLY_REGISTRATION=true`, guest checkout signup
  without a code fails validation with the same message as the register
  page; with the `fp_referral` cookie it succeeds.

---

## C. FlexPick branding

### C.1 Changes

1. **Logos.** Replace `public/images/logo-dark.png` and `logo-light.png`
   (both currently the SaaSykit mark) with PNGs derived from
   `frontend/public/images/logo.png` (blue icon, orange/blue FLEXPICK
   wordmark): `logo-dark.png` = original colours, for light backgrounds
   (email layout, PDF invoices, OG image); `logo-light.png` = white
   silhouette, for dark backgrounds. Export at 2× (600 px wide) so email
   clients render crisply at the layout's 1.6 rem height. Same filenames,
   so `email.blade.php`, `InvoiceService`, `InvoiceController` and
   `OpenGraphImageSettings` need no code change.
2. **Fallback strings.** `config/app.php` `'name'` default → `'FlexPick'`;
   `head.blade.php` and `social-cards.blade.php` drop the `'SaaSykit'`
   second argument to `config('app.name')` / `config('app.description')`;
   `config/invoices.php` seller defaults → `FlexPick` / empty address (the
   live value is admin-configured via `ConfigService`; this is only the
   fallback).
3. **Dead views.** Delete `resources/views/coming-soon/` (SaaSykit
   marketing pages, not routed).
4. **Admin panel.** `AdminPanelProvider` gets `->brandName('FlexPick')`,
   `->brandLogo()` / `->darkModeBrandLogo()` with the wordmark SVGs, as the
   dashboard panel already has.
5. **Left alone, deliberately:** the `SaaSykit\OpenGraphy` package
   namespace, `composer.json` metadata, `AGENTS.md`/docs, the recaptcha
   documentation link in admin `GeneralSettings`, and the
   `AuditMonetizationSeeder` comment.

### C.2 Testing
- `LayoutBrandingTest`: rendered home redirect target, `/pricing`,
  `/register`, `/login`, and a rendered `x-layouts.email` contain no
  `SaaSykit`; the email layout's `<img>` points at `images/logo-dark.png`.
- The new PNGs are checked into git; a test asserts both files exist and
  are PNGs (guards against a deploy without the assets).

---

## D. Out of scope

- Fan-out of audit emails to all workspace members.
- An admin UI for tenant bonus free runs / purchased credits.
- Re-homing audits between workspaces.
- Any change to the anonymous landing-page audit funnel beyond the renamed
  email-keyed entitlement methods.
