# Partner Reselling with Custom Pricing and Cash Payments

Status: Approved (design), ready for implementation planning
Date: 2026-08-30

## 1. Goal

Let a tenant subscribed to a "Partner Plan" resell platform access (public
subscription Plans and one-time Products) to the customers they refer, at
prices and quotas the partner configures themselves — no lower than the
admin-defined base values. For this initial version, all partner sales are
paid in cash, collected by the partner outside the platform, and remain
pending until the partner (or, for direct/unattributed customers, an admin)
confirms receipt and approves the order. Approval activates the exact
price/quota snapshot captured when the order was created.

## 2. Explicit non-goals for v1

- **No platform commission/settlement.** The platform takes no cut of a cash
  sale. It only tracks base price vs. partner price for informational margin
  reporting. No payout ledger, invoicing, or balance-owed mechanism.
- **No multi-currency partner pricing.** A partner sets one price in the
  store's default currency. Multi-currency reseller pricing is a future
  extension.
- **No cash support for seat-based or usage-based plans.** Cash checkout is
  available for one-time Products and `flat_rate` subscription Plans only —
  the same restriction the existing Offline payment provider already
  enforces. Metering/proration with no payment gateway behind the
  subscription is out of scope.
- **No multi-tier/sub-reseller chains.** A referred customer is attributed to
  exactly one partner tenant. Partners referring other partners is not
  supported.

## 3. Key terminology disambiguation

The codebase already has an unrelated concept named "Partner": a seeded
`audit-partner` Product/Plan (price $0, `is_visible = false`) manually
assigned by a super admin as a comped high-allowance audit plan. **This is
untouched by this feature.** The new "Partner Plan" here is a distinct,
paid, publicly-purchasable plan that grants reseller capability. Naming in
code/UI for the new feature should avoid colliding with the existing
`audit-partner` slug (e.g. use `reseller-partner` or similar for the new
plan's slug).

## 4. Partner identity & attribution

### 4.1 Partner Plan gate

A Plan grants reseller capability to a subscribing Tenant when its
`Product.metadata` contains `enables_reseller_program: true`. Capability is
active whenever the tenant has at least one currently-active subscription to
such a plan — same "read a boolean/value out of `Product.metadata` for any
active subscription" pattern already used by `AuditEntitlementService`. No
plan slug is hardcoded.

### 4.2 Partner referral link

New `PartnerReferralLink` model/table: `tenant_id` (FK), `code` (unique
string), `is_active` (bool). One active link per tenant for v1. Distinct
from the existing personal `ReferralCode` model — this is a deliberate,
separate mechanism (see rationale in §4.4).

### 4.3 Attribution storage

New nullable columns on `User`: `partner_tenant_id` (FK to `tenants`),
`partner_attributed_at` (timestamp), `partner_attribution_source` (enum:
`link`, `registration`, `login`, `order`).

- **Set-once, immutable.** Once `partner_tenant_id` is non-null, it is never
  overwritten by a later referral-link visit.
- **Trigger, generalized.** Attribution is written at the *first
  identifiable touchpoint* after a partner code has been seen: registration,
  login, or checkout — whichever happens first while a pending partner code
  exists in session and the user's `partner_tenant_id` is still null. This
  generalizes the "registration or first completed order" framing in the
  original request to also cover an existing account that later logs in
  through a partner link for the first time.
- **Anonymous pre-registration visitors**: the partner code lives in
  session/cookie only (reusing `ReferralService`'s existing session-tracking
  plumbing) and drives storefront pricing for that browsing session without
  any DB write, until a User record can be attributed.
- **Conflict handling**: if a user who already has `partner_tenant_id` set
  visits a *different* partner's referral link, attribution does not change.
  The storefront shows a notice identifying which partner the account is
  already associated with.

### 4.4 Rationale for a separate mechanism

The existing `Referral`/`ReferralCode` system tracks a referrer **User**
through a `pending → verified → paid → rewarded` state machine that grants a
discount code or fires a custom event. Partner attribution has fundamentally
different rules (partner identity is a **Tenant**, attribution is permanent
and drives storefront pricing rather than a one-time reward, and it must
never trigger a discount-code grant). Building it as a flag on the existing
system risks the two purposes interfering (e.g. reward-progression logic
firing for what should be a pricing-only attribution). The new mechanism
reuses only the low-level session/cookie detection plumbing from
`ReferralService`.

## 5. Reseller catalog & pricing/quota configuration

### 5.1 Eligible items

Any `Plan` (via its `Product`) or `OneTimeProduct` that is already public
(`is_visible = true`) is eligible for partner configuration. No separate
admin "resellable" flag is introduced — public visibility is the only gate.

### 5.2 Admin-defined quota allowlist

New `reseller_quota_keys` column (json array of metadata key strings) on
`Product` (governs all Plans under it) and on `OneTimeProduct`. Only keys on
this list are configurable by a partner. The floor for each key is **read
live** from the base item's current `metadata[$key]` value — there is no
separate stored "minimum" field, so a later admin increase in the base value
automatically raises the floor for every existing partner configuration.

### 5.3 Partner offering tables

- `partner_plan_offerings`: `tenant_id`, `plan_id`, `price` (int, minor
  units, default currency), `quota_overrides` (json), `is_enabled` (bool,
  default `false`). Unique on (`tenant_id`, `plan_id`).
- `partner_product_offerings`: same shape, `one_time_product_id` instead of
  `plan_id`.

A missing row for a given tenant+item means "not configured, currently
disabled" — rows are only created once a partner touches that item.

### 5.4 Validation (service-layer, not just UI)

On every save: `price >= base price` (current `PlanPrice`/
`OneTimeProductPrice` for the store's default currency); every key in
`quota_overrides` must be present in `reseller_quota_keys`; every value
`>= data_get(base metadata, key)`. Violations block the save.

### 5.5 Base-price/minimum increase handling

Because the floor is read live rather than copied, an admin raising a base
price or a quota's base value can retroactively put an existing partner
offering below the (new) minimum. This is detected at **read time**
whenever the offering is used to price a new order: such an offering is
treated as `is_below_minimum` (a computed property, not a stored flag) and
is blocked from generating new orders/checkouts until the partner edits it
back above the floor. Existing orders/subscriptions are never touched — they
carry their own price/quota snapshot (§6).

### 5.6 Dashboard UI

New Filament Dashboard resource, visible only to tenants with an active
Partner Plan subscription (§4.1), listing eligible Plans/OneTimeProducts
with editable price, per-key quota inputs (for allowlisted keys), and an
enable/disable toggle.

## 6. Cash payment lifecycle

### 6.1 Extending the Offline provider

`OfflineProvider` (`app/Services/PaymentProviders/Offline/OfflineProvider.php`)
already implements the payment-provider contract for local/manual payments
but currently assumes immediate activation (used today for admin-granted
comped access). It is extended, not replaced, to also support a
pending-approval mode, gated purely on **amount due**:

- Amount due `= 0` → unchanged behavior: immediate `SUCCESS`/`ACTIVE`
  ("Local orders are considered successful immediately" in
  `OrderService::create`, and the equivalent in `SubscriptionService::create`
  for `localSubscription`). This covers today's admin-comped-access use case
  exactly as before.
- Amount due `> 0` with the Offline provider selected → the order/
  subscription is created/transitioned to `PENDING` instead of auto-
  completing. This reuses the already-stubbed `OrderedOffline` event
  (`app/Events/Order/OrderedOffline.php`, fired today when a local order
  hits `PENDING` — its doc comment already anticipates "let the user know
  they need to pay offline") plus a new subscription-side equivalent event.
- `supportsPlan()` keeps its existing `flat_rate`-only restriction (§2).

### 6.2 Snapshot fields

New columns, written once at order/subscription creation and never mutated
by later catalog or base-price changes:

- `Order`: `partner_tenant_id` (nullable FK), `base_price_snapshot` (int),
  `quota_snapshot` (json), `subscription_id` (nullable FK, for renewal
  orders — §6.5), `type` (`purchase` | `renewal`).
- `Subscription`: `partner_tenant_id` (nullable FK), `base_price_snapshot`
  (int), `quota_snapshot` (json).

`partner_price_snapshot` is not a new column — it's the existing
`total_amount` (Order) / `price` (Subscription) column, which is already
immutable once set. For a partner-attributed purchase, `quota_snapshot` is
the partner's `quota_overrides` merged over base metadata; for a direct
(non-partner) purchase, it's still populated with the base metadata values,
so a later admin edit to a product's quotas never retroactively changes an
existing customer's entitlement.

### 6.3 Entitlement reads must prefer the snapshot

`AuditEntitlementService` (and any future quota consumer) must read
`subscription->quota_snapshot[$key] ?? data_get($product->metadata, $key)`
instead of unconditional `data_get($product->metadata, $key)`. This is a
required change — without it, partner-granted quota overrides would
silently collapse to the base plan's values at read time.

### 6.4 New order status

`OrderStatus::REJECTED` is added, distinct from `FAILED` (which remains
reserved for genuine payment-gateway failures), so a partner/admin
rejection is reported and messaged distinctly from a technical failure.
`SubscriptionStatus` needs no new case — a rejected or expired pending
subscription reuses the existing `CANCELED` value.

### 6.5 Expiration

New config keys (e.g. `cash_payments.pending_ttl_hours`, default 72) and a
new scheduled command `app:expire-pending-cash-orders` (registered in
`routes/console.php` alongside the existing audit-domain scheduled commands,
same `withoutOverlapping()->onOneServer()` pattern) that transitions stale
`PENDING` cash orders/subscriptions to `REJECTED`/`CANCELED`, writes a
system-actor row to the `order_approvals` audit log (§7.2), and fires the
customer expiration notification.

### 6.6 Renewal in cash

A recurring cash subscription does not auto-extend `ends_at` on its own —
each renewal creates a new `PENDING` `Order` (`type = 'renewal'`,
`subscription_id` set) that goes through the exact same partner/admin
approval UI and audit log as a new purchase. The renewal order's price,
`quota_snapshot`, and `partner_tenant_id` are copied from the **subscription
itself** (its locked-in values), never re-derived from the partner's current
catalog — a subscription's price doesn't change on renewal just because the
partner edited their offering afterward. If the renewal order isn't approved
by `ends_at`, the subscription moves to `PAST_DUE`; if still unapproved
after a further `cash_payments.pending_ttl_hours` grace window, it moves to
`CANCELED`.

## 7. Approval workflow, ownership & security

### 7.1 Partner Order Approvals

New Filament Dashboard resource, visible only to tenants with an active
Partner Plan, listing `Order`s where `partner_tenant_id` = the current
tenant and `status = PENDING` (both `purchase` and `renewal` types).
Displays customer identity, product/plan, `base_price_snapshot`, partner
price, `quota_snapshot`, and payment status. Actions: **Approve** (confirms
cash received, activates) and **Reject**, each accepting an optional
internal note.

Access within the tenant (who can view/act on this resource and the catalog
resource in §5.6, and who receives the notification in §9) is governed by
the existing tenant permission system (`TenantPermissionService`), the same
way other tenant-scoped dashboard resources already are — not hardcoded to
the tenant owner alone.

### 7.2 Idempotent, audited transitions

New `order_approvals` table: `order_id` (unique), `actor_type` (`partner` |
`admin` | `system`), `actor_user_id` (nullable, null for `system`),
`decision` (`approved` | `rejected`), `note` (nullable text), `decided_at`.
Every approval/rejection (including automated expiry) runs inside a DB
transaction with `lockForUpdate()` and a `WHERE status = PENDING` guard
before transitioning — a duplicate click or concurrent request is a no-op.
The unique constraint on `order_id` is a DB-level backstop against
double-approval.

### 7.3 Admin parallel capability

The same approval action is available in the Admin panel for any order —
covering direct-customer cash orders (no partner exists to approve them)
and a support-override path for a partner-owned order (e.g. an unresponsive
partner, a dispute). Admin actions write to the same `order_approvals` log
with `actor_type = admin`, giving one unified history regardless of who
acted.

### 7.4 Backend ownership enforcement

A policy check — `order->partner_tenant_id === $actingTenant->id` — gates
every approval action and every read of an individual order, subscription,
or catalog-offering record. This check lives in the action/controller
itself, not only as a list-view query scope, so a partner cannot reach
another partner's record by guessing a URL or UUID. The same policy
re-verifies "does this tenant currently have an active Partner Plan
subscription" at the moment of the action, not only at page load.

### 7.5 Partner Plan lapses

If a tenant's Partner Plan subscription becomes inactive: catalog-config and
approval actions are denied by the policy (§7.4); the storefront pricing
resolver (§8.1) stops offering that partner's prices for *new* checkouts,
falling back to base/default pricing; already-active customer
orders/subscriptions are untouched and continue under their current state.
If the Partner Plan later becomes active again, catalog and approval rights
resume automatically.

## 8. Storefront pricing & checkout integration

### 8.1 `PartnerPricingResolver`

A new service resolving the effective `partner_tenant_id` for the current
request: the authenticated user's `partner_tenant_id` if set, else an
anonymous visitor's session-stored referral code resolved through
`PartnerReferralLink`. The resolved tenant is only honored if it currently
has an active Partner Plan (§7.5); otherwise the resolver returns `null`
(base pricing).

This resolver is used by:
- The public pricing page and plan/product listing components, to display
  the partner's price/quota for enabled offerings.
- Checkout, which **re-derives** price/quota server-side from the resolver
  rather than trusting client-submitted values — this is what prevents a
  visitor from tampering with which partner's price they're charged.

### 8.2 Disabled items are hidden, not fallback-priced

When a partner has disabled (or never configured) an item, it does not
appear in that partner's attributed storefront view — it is not shown at a
fallback base price. This prevents a partner being forced into approving
sales of something they declined to resell.

## 9. Notifications

Standard Laravel notifications (mail channel), no new alerting
infrastructure:
- `PartnerNewPendingOrder` → the partner tenant's admin user(s), on order or
  renewal-order creation.
- `CustomerOrderApproved` / `CustomerOrderRejected` / `CustomerOrderExpired`
  → the customer, on the respective transition.

## 10. Reporting & support visibility

- Partner Order Approvals view and the customer-facing Orders dashboard
  section both show `base_price_snapshot`, partner price, and computed
  margin (partner price − base price).
- Admin's Order resource gains the same columns plus partner identity and a
  read-only tab showing the full approval + referral-attribution history,
  so support staff can identify the responsible partner for any customer.

## 11. Dashboard restoration

Revert the `shouldRegisterNavigation() => false` override added to
`OrderResource`, `SubscriptionResource`, and `TransactionResource` under
`app/Filament/Dashboard/Resources/` in commit `24ce804` — restoring standard
customer-facing navigation to these already-existing, already-functional
resources. No data model changes required for this item.

## 12. Data model summary (new/changed)

**New tables:**
- `partner_referral_links` (`tenant_id`, `code`, `is_active`)
- `partner_plan_offerings` (`tenant_id`, `plan_id`, `price`,
  `quota_overrides` json, `is_enabled`)
- `partner_product_offerings` (`tenant_id`, `one_time_product_id`, `price`,
  `quota_overrides` json, `is_enabled`)
- `order_approvals` (`order_id` unique, `actor_type`, `actor_user_id`,
  `decision`, `note`, `decided_at`)

**New columns:**
- `users`: `partner_tenant_id`, `partner_attributed_at`,
  `partner_attribution_source`
- `products`: `reseller_quota_keys` (json)
- `one_time_products`: `reseller_quota_keys` (json)
- `orders`: `partner_tenant_id`, `base_price_snapshot`, `quota_snapshot`,
  `subscription_id`, `type`
- `subscriptions`: `partner_tenant_id`, `base_price_snapshot`,
  `quota_snapshot`

**New enum values:**
- `OrderStatus::REJECTED`

## 13. Testing strategy

PHPUnit feature/unit tests, following the existing
`AuditEntitlementService`-style test patterns, covering: attribution
locking and conflict handling; quota-floor and price-floor validation
(including the below-minimum-after-admin-change case); approval idempotency
and cross-partner ownership enforcement; the expiration command; the
pricing resolver's fallback rules (including Partner Plan lapse); and the
cash renewal cycle (approve, reject, and expire paths). Pint and Larastan
must stay clean throughout, per the project's existing CI gate.
