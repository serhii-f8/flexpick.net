# Partner Catalog Restriction: Referred Customers See Only Enabled Items

Status: Approved (design), ready for implementation planning
Date: 2026-09-07
Builds on: `2026-08-30-partner-reselling-cash-payments-design.md` (the
"base spec") and `2026-09-07-partner-attribution-and-packages-design.md`
(the "attribution spec"). Where this document contradicts either, this
document wins; each such point is marked **Amends base §N**.

## 1. Goal

A customer attributed to a partner should see and be able to buy only the
plans and one-time products that partner has actually enabled for resale —
everywhere they'd encounter the catalog: the anonymous pre-registration
storefront, the authenticated dashboard, and checkout. An unconfigured item
should disappear from their view entirely, not fall back to the base price.

**Amends base §8.2.** That section (amended once already, 2026-09-05) chose
the opposite rule deliberately: because attribution is set-once and
immutable (base §4.3), permanently narrowing a customer's catalog to
whatever their partner happened to configure, with no way back, was judged
too restrictive. This document reverses that judgment: the owner has
decided the narrower catalog is the correct product behavior going forward,
accepting that trade-off explicitly. §8.2's fallback table no longer
applies — see §5 below for its replacement.

## 2. Scope

- Applies to **both** plans (subscriptions) and one-time products (report
  packages) — the same rule, since both already have their own offering
  model (`PartnerPlanOffering` / `PartnerProductOffering`).
- Applies to **every catalog surface that actually exists**: `route('pricing')`
  requires authentication (`PricingPageTest::test_guest_is_redirected_to_login`
  confirms a guest is redirected to `/login`, never shown a catalog) — so in
  the current app there is no reachable anonymous storefront page to filter.
  The real surfaces are the authenticated `/pricing` page and the Filament
  dashboard's change-plan page (which reuses the same plan-listing
  component). `purchasableFor()` still accepts `?User $user = null` and
  resolves an anonymous visitor's partner via the `fp_rc` cookie, matching
  `decoratePlans()`/`decorateProducts()`'s existing signature — so it is
  correct by construction if a guest-facing catalog page is ever added —
  but no such page exists to wire it into today.
- **Pre-existing bug found while scoping this work, fixed as a prerequisite**:
  `App\View\Components\Filament\Plans\All::calculateViewData()` — the
  component the change-plan page actually renders — overrides its parent
  and never calls `decoratePlans()` at all, so that page has never shown
  partner pricing to anyone, attributed or not (confirmed: no commit on
  this branch has touched this file, and no test exercises it). This is
  fixed as part of this work because `purchasableFor()` filtering is only
  meaningful once the surface it filters is also correctly priced.
- Applies to **checkout**, not just display: a request to buy a
  non-enabled item is rejected server-side, the same way
  `SubscriptionService::canChangeSubscriptionPlan()` already rejects a
  partner tenant's self-service plan change.
- **Unaffected**: a non-attributed (direct) customer's catalog and pricing
  — nothing here changes for them. The admin panel's own resource views are
  unaffected — an admin always sees the full catalog. `PartnerPricingResolver`'s
  existing price-substitution logic (decorate methods) is unchanged; this
  spec adds a filtering step alongside it, not a replacement for it.

## 3. `PartnerPricingResolver::purchasableFor()`

A new method on the existing resolver (`app/Services/PartnerPricingResolver.php`),
called only by the surfaces in §2 that gate *purchase*, not by every
consumer of `decoratePlans()`/`decorateProducts()`:

```php
/**
 * @param Collection<int, Plan|OneTimeProduct> $items
 */
public function purchasableFor(Collection $items, ?User $user): Collection
```

- Resolves the effective partner tenant exactly as `decoratePlans()`/
  `decorateProducts()` already do: the authenticated user's
  `partner_tenant_id` if set, else an anonymous visitor's `fp_rc` cookie
  (§3 of the attribution spec), else no partner.
- If no partner tenant resolves (direct customer, or the resolved tenant's
  Partner Plan has lapsed), returns `$items` unchanged — this is a no-op
  for every existing, non-attributed flow.
- If a partner tenant resolves, filters `$items` down to those with a
  *usable* offering for that tenant: `is_enabled = true` and not below the
  live minimum — the exact same "usable offering" test
  `usablePlanOffering()`/`usableProductOffering()` already apply for price
  substitution. An item with no offering row at all is treated identically
  to one explicitly disabled (both already mean "not usable" everywhere
  else in this codebase).

## 4. Display call sites

`View\Components\Plans\All`, `View\Components\Products\All`, and their
Filament dashboard equivalents call `purchasableFor()` on the fetched
collection immediately before building `groupedPlans` / `tierSections` (the
grouping Task 10 already built). A tier section that ends up with zero
items after filtering is omitted from `tierSections` the same way it
already is for a tier with zero *matching* items today — no new empty-tier
handling needed, the existing null-safety covers it.

The dashboard's change-plan page (`SubscriptionResource`'s change-plan
route) renders through the same Filament plans component, so it is covered
automatically — no separate change needed there beyond the shared
component picking up the filter.

## 5. Empty catalog

When `purchasableFor()` leaves zero items for an attributed buyer (a new
partner who hasn't configured Pricing Settings yet, per §2), the affected
page shows an empty-state message instead of an empty grid — "Nothing
available yet — check back soon" (exact copy is an implementation detail,
not a design constraint). This replaces base §8.2's fallback table, which
no longer has any row that can occur: a filtered-out item is never shown at
any price, base or partner.

## 6. Checkout enforcement

`SubscriptionController`, `CheckoutService`, and the two checkout forms
(`SubscriptionCheckoutForm` / `ProductCheckoutForm`) already re-derive
price/quota server-side rather than trusting client input (base §8.1) —
this is the existing seam to extend. Before finalizing a purchase, each
re-checks `purchasableFor([$targetPlanOrProduct], $buyer)->isNotEmpty()`
for the buyer attempting to check out; if empty, the purchase is rejected
(redirect with an error, matching the existing pattern
`SubscriptionController::changePlan()` uses when
`canChangeSubscriptionPlan()` fails).

**Consequence, stated explicitly:** once this ships, an attributed
customer can no longer complete a base-priced, no-partner-involvement
purchase at all (the old §8.2 table's "not configured → base price →
gateway, no partner" row becomes unreachable) — every purchase they
complete will be for a currently-enabled, partner-priced item. This is the
intended effect of the reversal in §1, not a side effect to guard against.

## 7. Grandfathering existing purchases

Nothing about an *existing* subscription or one-time purchase changes when
its item is later disabled. `purchasableFor()` only filters what's offered
for a **new** purchase or subscription change going forward:

- The customer's current subscription keeps working exactly as it does
  today — no forced cancellation, no forced plan change.
- Their own subscription/order history (`OrderResource`, subscription
  detail pages) is unaffected by this spec; those views already show what a
  customer owns, independent of current catalog visibility.
- If a customer with a grandfathered, now-disabled plan opens the
  change-plan page, their current plan need not appear in the *offered*
  list (per §4) — they are choosing a new plan, not confirming their
  existing one.

## 8. Testing

- `PartnerPricingResolverTest`: `purchasableFor()` — no-op for a
  non-attributed buyer; filters correctly for an attributed buyer across
  enabled / disabled / never-configured items; a lapsed Partner Plan
  behaves like no attribution.
- `PricingPageTest` / the dashboard plans-component tests: an attributed
  guest/customer sees only enabled items; a tier with zero enabled items is
  omitted; the zero-items empty state renders.
- A focused checkout test per surface (subscription checkout, product
  checkout): an attributed buyer attempting to check out a disabled item is
  rejected; an attributed buyer's existing subscription to a since-disabled
  plan is untouched by simply viewing/renewing it.
- `DashboardMenuItemsTest` / change-plan tests: confirm the change-plan
  page's offered list is filtered the same way.

## 9. Out of scope

- The public marketing site (`frontend/`, the static Astro build) is
  handled separately (2026-09-07, price figures removed from static pages
  entirely) and is not part of this spec.
- No change to how `partner_tenant_id` is stamped on orders (attribution
  spec §8.2 / this codebase's Task 12) — that stamping logic is unrelated
  to catalog *visibility* and stays as-is.
- No change to `PartnerCatalogService`'s offering-management API
  (`setPlanOffering()` / `setProductOffering()` / the price-floor
  validation) — this spec only adds a read-side filter on top of the
  offerings that already exist.
