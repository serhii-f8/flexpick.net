# Partner Reselling — Storefront Pricing, Reporting & Nav Restoration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show an attributed customer their partner's price on the storefront, charge that price server-side through the cash (Offline) checkout only, surface base/partner/margin reporting to partners and admins (but never to the customer), and restore the customer dashboard's Orders/Subscriptions/Transactions navigation — Plan 3 of 3 for the Partner Reselling feature.

**Architecture:** One new service, `PartnerPricingResolver`, becomes the single answer to "does a usable partner offering exist for this buyer and this item?" It resolves the partner tenant for both an authenticated user (`users.partner_tenant_id`) and an anonymous visitor (session referral code), and returns the offering only when it is enabled, owned by a currently-active partner, and at or above the live admin floor. Plan 2's `PurchaseSnapshotService` already had that logic as two *private* methods keyed on `User` only; Task 2 lifts it into the resolver and makes the snapshot service delegate, so the read side (storefront) and the write side (order snapshot) can never disagree. The storefront decorates plan/product models with a transient `partner_price` attribute; `CalculationService` substitutes the partner price into the subtotal it computes; and checkout narrows the payment-provider list to Offline whenever a partner price is in play. Gateway product/price synchronisation is untouched — `CalculationService::getPlanPrice()` / `getOneTimeProductPrice()` keep returning unmodified `PlanPrice` / `OneTimeProductPrice` models, which is what all five gateway providers call.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5.6, Livewire 4, PHPUnit 11 (no Pest, no `RefreshDatabase` — see Global Constraints).

**Spec:** `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md` (§8, §10 and §11; §4–§5 shipped in Plan 1, §6–§7 and §9 shipped in Plan 2). **§8.2 and §10 were amended on 2026-09-05 immediately before this plan was written** — read the amendment notes inside those two sections, not just the surrounding prose. The amendments are the binding requirements.

**Plan 1 (prerequisite, on the branch):** `backend/docs/superpowers/plans/2026-08-30-partner-reselling-foundation.md` — `PartnerReferralLink`, `PartnerPlanOffering`, `PartnerProductOffering`, `PartnerCapabilityService`, `PartnerAttributionService`, `PartnerCatalogService`, `users.partner_tenant_id`, `products.reseller_quota_keys`, the two partner catalog dashboard resources.

**Plan 2 (prerequisite, on the branch):** `backend/docs/superpowers/plans/2026-09-05-partner-reselling-cash-payments.md` — `orders`/`subscriptions` snapshot columns, `OrderStatus::REJECTED`, `OrderType`, `order_approvals`, `PurchaseSnapshotService`, `CashSubscriptionService`, `OrderApprovalService`, the Partner Order Approvals queue, admin approve/reject, the renewal and expiry commands, and the four cash-transition emails.

## Global Constraints

- Run all commands inside the dev container: `docker compose exec laravel.test <command>`, from the repo root. (If a command reports "file not found", the container working directory does not map the way you assumed — run `php artisan …` from `backend/` on the host instead.)
- Test base class is `Tests\Feature\FeatureTest` (extends `Tests\TestCase`) — **not** `RefreshDatabase`/`DatabaseTransactions`. It runs `migrate:fresh` + seeds **once per test class** (`static bool $setUpHasRunOnce`), so rows persist across test methods in the same class. Use unique factory data per test method; never assume an empty table.
- `FeatureTest` gives you `$this->createTenant()`, `$this->createUser(?Tenant $tenant = null, array $tenantPermissions = [], array $attributes = [])`, and `$this->createAdminUser()`. Use them rather than hand-rolling factories for tenants and users.
- Every new migration must have a working `down()`. **This plan adds no migrations** — every column it reads was created by Plans 1 and 2.
- Every enum in this codebase is a PHP backed `enum` under `App\Constants`.
- Filament is v5.6: form/table layouts use `->schema([...])`, computed table columns use `TextColumn::make('x')->getStateUsing(fn ($record) => …)`, infolist entries use `TextEntry::make('x')->getStateUsing(...)`, and table actions are tested with `->callTableAction('name', $record, data: [...])->assertHasNoTableActionErrors()`.
- Money is stored in **minor units** (integer cents) and rendered with the global `money($amount, $currencyCode)` helper from `saasykit/laravel-money`.
- **The store is single-currency.** `CurrencyService::getCurrency()` always returns `config('app.default_currency')` — there is no per-visitor currency. Partner offerings are priced in that one currency (spec §2). Do **not** add currency-conversion or currency-matching logic anywhere in this plan; there is nothing to match against.
- The Offline payment provider row is seeded with `is_active = false`. Any test that exercises a cash flow must activate it first: `PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->update(['is_active' => true]);`
- Run `vendor/bin/pint --format agent` before each commit; `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` must stay clean. Note: `vendor/bin/phpstan analyse` reports **one pre-existing error in `app/Services/AuditGroupDeltaService.php`** that predates this branch. That single error is the expected baseline — do not "fix" it, and do not treat it as your regression.
- **Do not use `pint --dirty`** — the backend bind-mount excludes `.git`, so it finds no dirty files and vacuously reports `passed`. Run plain `pint`.
- Out of scope, per spec §2: platform commission/settlement, multi-currency partner pricing, seat-based or usage-based cash plans, sub-reseller chains.
- Out of scope, per the Plan 1 answer to the "Partner Plan" naming collision (spec §3): the seeded `audit-partner` comped Product/Plan is a different, unrelated concept. Do not touch it.

## Design decisions this plan locks in

These are decisions the spec leaves to implementation. They are binding for every task below.

1. **One resolver, two call sites.** `PartnerPricingResolver` is the only place that answers "is there a usable partner offering for this buyer and this item?". The storefront reads it; `PurchaseSnapshotService` (write side) delegates to it. Duplicating the enabled/below-minimum/active-partner rules anywhere else is a defect.

2. **Partner pricing is cash-only.** A partner price is only ever charged through the Offline provider. `CalculationService::getPlanPrice()` and `getOneTimeProductPrice()` return the **unmodified** `PlanPrice` / `OneTimeProductPrice` model, because all five gateway providers (`StripeProvider`, `PaddleProvider`, `PolarProvider`, `CreemProvider`, `LemonSqueezyProvider`) call them to create and look up gateway-side products and prices. Partner pricing enters through the *totals* methods (`calculatePlanTotals`, `calculateCartTotals`, `calculateOrderTotals`) only. If you find yourself editing `getPlanPrice()`, you have taken a wrong turn.

3. **Unconfigured items stay in the catalog at base price (spec §8.2, as amended).** An item the partner disabled or never configured is shown to the attributed customer at the base price and checks out normally through a gateway. It is *not* hidden. The guarantee that a partner is never handed an approval for an item they declined is enforced on the write side, by Plan 2's rule that `partner_tenant_id` is stamped only when a usable offering exists.

4. **Margin is never shown to the customer (spec §10, as amended).** The customer-facing Dashboard `OrderResource` shows the amount paid and the partner's name. It must not show `base_price_snapshot` and must not show a computed margin. Task 9 asserts the absence, not just the presence.

5. **Graceful degradation when Offline is off.** The Offline `PaymentProvider` row is a global admin toggle seeded inactive. If a usable partner offering exists but Offline is not active, the resolver returns `null` — the customer sees the base price and buys through a gateway. Rendering a partner price the customer cannot possibly pay, or throwing `NoPaymentProvidersAvailableException` at them, are both worse outcomes than quietly falling back. This check lives in `PartnerPricingResolver` so display and checkout degrade together and cannot disagree.

6. **The resolver is a `scoped` binding with an explicit `flush()`.** The storefront renders one plan card per plan and asks the resolver each time; without a per-request instance that is N round-trips through `PartnerCapabilityService`'s subscription lookup. `scoped` gives one instance per request (and per queue job). **The test consequence is unavoidable and you must handle it:** because `FeatureTest` does not refresh the database between test methods and the container is not rebuilt between them either, a test that creates or edits an offering *after* something has already resolved it will read a stale memo. Every test that mutates offerings mid-test must call `app(PartnerPricingResolver::class)->flush()` afterwards. Tasks below do this explicitly where required.

7. **Storefront decoration, not restructuring.** `PlanService::getAllPlansWithPrices()` and `OneTimeProductService::getAllProductsWithPrices()` are called from admin filters as well as the storefront; they stay partner-unaware. The two public `App\View\Components` decorate the collections they already fetch by setting a transient `partner_price` attribute on each model. Blade renders `partner_price` when it is non-null, base otherwise.

8. **`calculateOrderTotals()` is the authoritative product price.** It is what actually writes `order_items.price_per_unit`, `orders.total_amount` and `orders.total_amount_after_discount` (called from `OrderService::updateOrderProducts()`). `calculateCartTotals()` is the display counterpart shown in the checkout form. Both must apply the partner price or the customer is quoted one number and charged another.

9. **Price is always re-derived, never accepted from the request.** Every totals method resolves the offering itself from the authenticated user (or session code). No Livewire property, form field, or query parameter carries a price. This is the tamper guard spec §8.1 asks for, and it is satisfied by construction rather than by validation.

## File structure

**New — `app/Services/`**
- `PartnerPricingResolver.php` — the single "usable offering" resolver, for authenticated and anonymous visitors alike. Public API: `resolvePartnerTenant()`, `usablePlanOffering()`, `usableProductOffering()`, `planPrice()`, `productPrice()`, `decoratePlans()`, `decorateProducts()`, `flush()`.

**New — tests**
- `tests/Feature/Services/PartnerPricingResolverTest.php`
- `tests/Feature/Services/PartnerStorefrontPricingTest.php`
- `tests/Feature/Services/PartnerCheckoutPricingTest.php`
- `tests/Feature/Livewire/Checkout/PartnerPaymentProviderRestrictionTest.php`
- `tests/Feature/Filament/Admin/OrderPartnerReportingTest.php`
- `tests/Feature/Filament/Dashboard/CustomerOrderPartnerVisibilityTest.php`
- `tests/Feature/Filament/Dashboard/DashboardNavigationTest.php`

**Modified**
- `app/Providers/AppServiceProvider.php` — `scoped` binding for `PartnerPricingResolver`.
- `app/Services/CashPayments/PurchaseSnapshotService.php` — delegates offering resolution to the resolver; its two private `usable*Offering()` methods are deleted.
- `app/View/Components/Plans/All.php` — decorate plans with `partner_price`.
- `app/View/Components/Products/All.php` — decorate products with `partner_price`.
- `resources/views/components/plans/one.blade.php` — render partner price when present.
- `resources/views/components/products/all.blade.php` — render partner price when present.
- `app/Services/CalculationService.php` — partner price in `calculatePlanTotals()`, `calculateCartTotals()`, `calculateOrderTotals()`.
- `app/Livewire/Checkout/SubscriptionCheckoutForm.php` — Offline-only provider list when a partner price applies.
- `app/Livewire/Checkout/ProductCheckoutForm.php` — same, for one-time products.
- `app/Filament/Admin/Resources/Orders/OrderResource.php` — partner columns and the approval/attribution history section (§10).
- `app/Filament/Dashboard/Resources/Orders/OrderResource.php` — "Sold through" column/entry, nav restoration (§10, §11).
- `app/Filament/Dashboard/Resources/Subscriptions/SubscriptionResource.php` — nav restoration (§11).
- `app/Filament/Dashboard/Resources/Transactions/TransactionResource.php` — nav restoration (§11).

**Not modified — and deliberately so**
- `app/Services/PlanService.php`, `app/Services/OneTimeProductService.php` — catalog queries stay partner-unaware (decision 7).
- `app/Services/CalculationService.php::getPlanPrice()` / `getOneTimeProductPrice()` — gateway product sync depends on these returning base prices (decision 2).
- Any file under `app/Services/PaymentProviders/` — no gateway ever sees a partner price.
- `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php` — it also has a `shouldRegisterNavigation()` override, but that one is a real conditional predating commit `24ce804`. §11 covers only the three resources that commit touched.

---
### Task 1: `PartnerPricingResolver` — one answer to "is there a usable offering?"

**Files:**
- Create: `app/Services/PartnerPricingResolver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Services/PartnerPricingResolverTest.php`

**Interfaces:**
- Consumes: `PartnerAttributionService::pendingCode(): ?string`, `PartnerAttributionService::resolveTenantForCode(string $code): ?Tenant`, `PartnerCapabilityService::tenantIsActivePartner(Tenant $tenant): bool`, `PartnerCatalogService::isPlanOfferingBelowMinimum(PartnerPlanOffering $offering): bool`, `PartnerCatalogService::isProductOfferingBelowMinimum(PartnerProductOffering $offering): bool` — all already on the branch from Plan 1.
- Produces:
  - `PartnerPricingResolver::resolvePartnerTenant(?User $user = null): ?Tenant`
  - `PartnerPricingResolver::usablePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering`
  - `PartnerPricingResolver::usableProductOffering(?User $user, OneTimeProduct $product): ?PartnerProductOffering`
  - `PartnerPricingResolver::planPrice(?User $user, Plan $plan): ?int`
  - `PartnerPricingResolver::productPrice(?User $user, OneTimeProduct $product): ?int`
  - `PartnerPricingResolver::flush(): void`

**Why the Offline check lives here:** decision 5. Putting it in the resolver means every consumer — storefront, totals, provider list — degrades identically. If it lived only in the checkout form, the pricing page would advertise a price nobody could pay.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/PartnerPricingResolverTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PartnerReferralLink;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerPricingResolverTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function resolver(): PartnerPricingResolver
    {
        return app(PartnerPricingResolver::class);
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** A resellable plan priced at $49 base, with a $79 enabled partner offering. */
    private function sellablePlan(Tenant $partnerTenant, int $basePrice = 4900, int $partnerPrice = 7900): array
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_active' => true, 'is_visible' => true]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => $partnerPrice,
            'quota_overrides' => ['audit_diagnostic_credits' => 3],
            'is_enabled' => true,
        ]);

        return [$plan, $offering];
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_an_attributed_user_gets_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $this->assertSame(7900, $this->resolver()->planPrice($user, $plan));
        $this->assertTrue($this->resolver()->resolvePartnerTenant($user)->is($partnerTenant));
    }

    public function test_an_unattributed_user_gets_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->createUser();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->resolvePartnerTenant($user));
    }

    public function test_an_anonymous_visitor_with_a_session_code_gets_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);

        $link = PartnerReferralLink::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'is_active' => true,
        ]);

        session([\App\Constants\SessionConstants::PARTNER_REFERRAL_CODE => $link->code]);
        $this->resolver()->flush();

        $this->assertSame(7900, $this->resolver()->planPrice(null, $plan));
    }

    public function test_a_disabled_offering_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $offering] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $offering->update(['is_enabled' => false]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    public function test_an_offering_below_the_live_minimum_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $offering] = $this->sellablePlan($partnerTenant, basePrice: 4900, partnerPrice: 5900);
        $user = $this->attributedUser($partnerTenant);

        // Admin raises the base price above what the partner is charging.
        $plan->prices()->update(['price' => 9900]);
        $this->resolver()->flush();

        $this->assertTrue(app(\App\Services\PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    public function test_a_lapsed_partner_plan_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        Subscription::where('tenant_id', $partnerTenant->id)
            ->update(['status' => SubscriptionStatus::INACTIVE->value]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->resolvePartnerTenant($user));
    }

    public function test_an_inactive_offline_provider_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => false]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    public function test_a_one_time_product_offering_resolves_the_same_way(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->attributedUser($partnerTenant);

        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $this->resolver()->flush();

        $this->assertSame(7900, $this->resolver()->productPrice($user, $product));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPricingResolverTest`

Expected: FAIL — `Class "App\Services\PartnerPricingResolver" does not exist`.

- [ ] **Step 3: Write the resolver**

Create `app/Services/PartnerPricingResolver.php`:

```php
<?php

namespace App\Services;

use App\Constants\PaymentProviderConstants;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single answer to "does a usable partner offering exist for this buyer
 * and this item?" (spec §8.1).
 *
 * Both sides of the feature ask this service rather than re-deriving the
 * rules: the storefront and the totals calculation read it, and
 * PurchaseSnapshotService delegates to it when stamping partner_tenant_id
 * onto a new order. Two implementations of "usable" would drift, and the
 * drift would be a customer quoted one price and charged another.
 *
 * "Usable" means all four of:
 *   - the buyer resolves to a partner tenant (attributed user, or an
 *     anonymous visitor carrying an active referral code in session),
 *   - that tenant's Partner Plan is currently active (spec §7.5),
 *   - the offering exists and is_enabled,
 *   - the offering is still at or above the live admin floor (spec §5.5).
 *
 * Plus one operational precondition: the Offline payment provider must be
 * active. A partner price can only ever be charged in cash, so advertising
 * one while Offline is switched off would quote a price with no checkout
 * behind it. Failing closed here makes display and checkout degrade together.
 */
class PartnerPricingResolver
{
    /** @var array<int, Tenant|null> keyed by user id, 0 for an anonymous visitor */
    private array $tenantMemo = [];

    /** @var array<string, PartnerPlanOffering|null> keyed by "<userKey>:<planId>" */
    private array $planOfferingMemo = [];

    /** @var array<string, PartnerProductOffering|null> keyed by "<userKey>:<productId>" */
    private array $productOfferingMemo = [];

    private ?bool $offlineActiveMemo = null;

    public function __construct(
        private PartnerAttributionService $attributionService,
        private PartnerCapabilityService $capabilityService,
        private PartnerCatalogService $catalogService,
    ) {}

    /**
     * Drop every memo. Required in tests whenever offerings, subscriptions or
     * the Offline provider row are mutated after something has already
     * resolved them — the container binding is scoped, so the instance
     * outlives the change.
     */
    public function flush(): void
    {
        $this->tenantMemo = [];
        $this->planOfferingMemo = [];
        $this->productOfferingMemo = [];
        $this->offlineActiveMemo = null;
    }

    public function resolvePartnerTenant(?User $user = null): ?Tenant
    {
        $key = $this->userKey($user);

        if (array_key_exists($key, $this->tenantMemo)) {
            return $this->tenantMemo[$key];
        }

        return $this->tenantMemo[$key] = $this->computePartnerTenant($user);
    }

    public function usablePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering
    {
        $key = $this->userKey($user).':'.$plan->id;

        if (array_key_exists($key, $this->planOfferingMemo)) {
            return $this->planOfferingMemo[$key];
        }

        return $this->planOfferingMemo[$key] = $this->computePlanOffering($user, $plan);
    }

    public function usableProductOffering(?User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $key = $this->userKey($user).':'.$product->id;

        if (array_key_exists($key, $this->productOfferingMemo)) {
            return $this->productOfferingMemo[$key];
        }

        return $this->productOfferingMemo[$key] = $this->computeProductOffering($user, $product);
    }

    public function planPrice(?User $user, Plan $plan): ?int
    {
        $offering = $this->usablePlanOffering($user, $plan);

        return $offering === null ? null : (int) $offering->price;
    }

    public function productPrice(?User $user, OneTimeProduct $product): ?int
    {
        $offering = $this->usableProductOffering($user, $product);

        return $offering === null ? null : (int) $offering->price;
    }

    private function computePartnerTenant(?User $user): ?Tenant
    {
        $tenant = $user !== null && $user->partner_tenant_id !== null
            ? $user->partnerTenant
            : $this->tenantFromPendingCode();

        if ($tenant === null) {
            return null;
        }

        return $this->capabilityService->tenantIsActivePartner($tenant) ? $tenant : null;
    }

    private function tenantFromPendingCode(): ?Tenant
    {
        $code = $this->attributionService->pendingCode();

        return $code === null ? null : $this->attributionService->resolveTenantForCode($code);
    }

    private function computePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering
    {
        $tenant = $this->resolvePartnerTenant($user);

        if ($tenant === null || ! $this->offlineProviderIsActive()) {
            return null;
        }

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isPlanOfferingBelowMinimum($offering) ? null : $offering;
    }

    private function computeProductOffering(?User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $tenant = $this->resolvePartnerTenant($user);

        if ($tenant === null || ! $this->offlineProviderIsActive()) {
            return null;
        }

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)
            ->where('one_time_product_id', $product->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isProductOfferingBelowMinimum($offering) ? null : $offering;
    }

    private function offlineProviderIsActive(): bool
    {
        return $this->offlineActiveMemo ??= PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->where('is_active', true)
            ->exists();
    }

    private function userKey(?User $user): int
    {
        return $user?->id ?? 0;
    }
}
```

- [ ] **Step 4: Register the scoped binding**

In `app/Providers/AppServiceProvider.php`, inside `register()`, alongside the existing `$this->app->bind(...)` calls, add:

```php
        // Scoped, not bound fresh: the pricing page asks this service once per
        // plan card, and each miss would re-run the partner's active-subscription
        // lookup. One instance per request. Tests that mutate offerings after a
        // resolution must call flush().
        $this->app->scoped(\App\Services\PartnerPricingResolver::class);
```

Add `use App\Services\PartnerPricingResolver;` to the file's imports and drop the FQCN if the surrounding code imports its classes (it does — match the file's existing style).

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPricingResolverTest`

Expected: PASS — 8 tests.

If `test_an_anonymous_visitor_with_a_session_code_gets_the_partner_price` fails with a null price, check that `PartnerReferralLinkFactory` generates a unique `code`; `FeatureTest` does not truncate tables between methods, so a colliding code from an earlier method resolves to the wrong tenant.

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Services/PartnerPricingResolver.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/Services/PartnerPricingResolverTest.php
git commit -m "feat(partner): add PartnerPricingResolver for storefront partner pricing"
```

---

### Task 2: `PurchaseSnapshotService` delegates to the resolver

**Files:**
- Modify: `app/Services/CashPayments/PurchaseSnapshotService.php`
- Test: `tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php` (existing — must keep passing unchanged)

**Interfaces:**
- Consumes: `PartnerPricingResolver::usablePlanOffering()`, `usableProductOffering()`, `resolvePartnerTenant()` from Task 1.
- Produces: no signature changes. `forPlan(User $user, Plan $plan): array`, `forProduct(User $user, OneTimeProduct $product): array` and `partnerTenantFor(User $user): ?Tenant` keep their exact current signatures and return shapes, because `SubscriptionService:85` and `CheckoutService` call them.

**This is a refactor with one intended behaviour change.** `PurchaseSnapshotService` currently resolves offerings itself, with no Offline-active check. After delegating, an order created while the Offline provider is inactive will no longer be stamped with `partner_tenant_id` — which is correct: a partner price that cannot be charged must not create a partner approval task either. That is the whole point of putting the check in one place (decision 5). The existing test suite must be re-run to confirm nothing *else* moved.

- [ ] **Step 1: Run the existing tests to establish the green baseline**

Run: `docker compose exec laravel.test php artisan test --filter=PurchaseSnapshotServiceTest`

Expected: PASS. Record the test count — you must end this task with the same count still passing. If it fails before you have changed anything, stop and report: the branch is not in the state this plan assumes.

- [ ] **Step 2: Write the failing test for the new Offline-inactive behaviour**

Append this method to `tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php`. Match the file's existing helper names — read the file first and reuse whatever it already uses to build a partner tenant, a plan and an attributed user rather than inventing parallel helpers:

```php
    public function test_no_partner_is_stamped_when_the_offline_provider_is_inactive(): void
    {
        // Same arrangement as the "attributed user gets partner_tenant_id" test
        // in this file — reuse this file's existing helpers for these three lines.
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        \App\Models\PaymentProvider::where('slug', \App\Constants\PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => false]);
        app(\App\Services\PartnerPricingResolver::class)->flush();

        $snapshot = app(\App\Services\CashPayments\PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        // The base snapshot is still written — it is written for every purchase,
        // partner-attributed or not (spec §6.2).
        $this->assertNotNull($snapshot['base_price_snapshot']);
    }
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PurchaseSnapshotServiceTest`

Expected: FAIL on the new method — `Failed asserting that 2 is null` (or whatever the partner tenant id is), because the current implementation does not consult the Offline provider.

- [ ] **Step 4: Delegate**

In `app/Services/CashPayments/PurchaseSnapshotService.php`:

Replace the constructor:

```php
    public function __construct(
        private PartnerCatalogService $catalogService,
        private PartnerPricingResolver $pricingResolver,
    ) {}
```

`PartnerCapabilityService` is no longer used here — delete its `use` import. Add `use App\Services\PartnerPricingResolver;`.

Replace `partnerTenantFor()` with a delegation:

```php
    /**
     * The partner tenant this buyer is attributed to, but only while that
     * tenant's Partner Plan is currently active (spec §7.5).
     *
     * Delegates so the write side can never disagree with the storefront about
     * who the partner is.
     */
    public function partnerTenantFor(User $user): ?Tenant
    {
        return $this->pricingResolver->resolvePartnerTenant($user);
    }
```

**Delete** both private methods `usablePlanOffering()` and `usableProductOffering()` in their entirety, including their shared doc comment, and change the two call sites inside `forPlan()` and `forProduct()`:

```php
        $offering = $this->pricingResolver->usablePlanOffering($user, $plan);
```

```php
        $offering = $this->pricingResolver->usableProductOffering($user, $product);
```

Everything else in the file — `basePriceOrNull()`, the `PartnerOfferingValidationException` catch, the `quota_snapshot` merge, the return shapes — stays exactly as it is. `PartnerPlanOffering` and `PartnerProductOffering` may now be unused imports; remove any that Pint or PHPStan flags.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=PurchaseSnapshotServiceTest`

Expected: PASS — the original count from Step 1, plus 1.

- [ ] **Step 6: Run the rest of the Plan 2 cash suite for regressions**

Run: `docker compose exec laravel.test php artisan test --filter='CashOrderCreationTest|CashSubscriptionServiceTest|OrderApprovalServiceTest|PartnerOrderOwnershipTest|SubscriptionSnapshotOnCreateTest'`

Expected: PASS. These tests create cash orders, so they already activate the Offline provider; if any now fails with a null `partner_tenant_id`, it is a test that was silently relying on partner stamping without an active Offline provider — activate the provider in that test's arrangement rather than weakening the resolver.

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Services/CashPayments/PurchaseSnapshotService.php backend/tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php
git commit -m "refactor(partner): delegate snapshot offering resolution to PartnerPricingResolver"
```

---
### Task 3: Storefront plan cards show the partner price

**Files:**
- Modify: `app/Services/PartnerPricingResolver.php` (add `decoratePlans()`)
- Modify: `app/View/Components/Plans/All.php`
- Modify: `resources/views/components/plans/one.blade.php`
- Test: `tests/Feature/Services/PartnerStorefrontPricingTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::planPrice(?User $user, Plan $plan): ?int` from Task 1.
- Produces: `PartnerPricingResolver::decoratePlans(Collection $plans, ?User $user): Collection` — sets a transient `partner_price` (`?int`) and `partner_tenant_name` (`?string`) attribute on every `Plan` in the collection and returns the same collection instance.

**Why decoration and not a query change:** `PlanService::getAllPlansWithPrices()` is also called by `app/Filament/Admin/Resources/Subscriptions/Pages/ListSubscriptions.php:68` to populate an admin filter dropdown. Partner pricing has no business there. Decorating in the view component keeps the change on the storefront where it belongs (decision 7).

`partner_price` is set as a plain model attribute, not a database column. Eloquent allows this — the attribute lives in the model's attribute bag and is never persisted, because nothing calls `save()` on these read-only listing models. Blade reads it with `$plan->partner_price`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/PartnerStorefrontPricingTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerStorefrontPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function visiblePlan(int $basePrice = 4900): Plan
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        return $plan;
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_a_configured_plan_is_decorated_with_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decoratePlans(collect([$plan]), $user)
            ->first();

        $this->assertSame(7900, $decorated->partner_price);
        $this->assertSame($partnerTenant->name, $decorated->partner_tenant_name);
    }

    public function test_an_unconfigured_plan_is_left_at_base_price(): void
    {
        // Spec §8.2 as amended: an item the partner never configured stays in
        // the catalog at base price. It must NOT be hidden or nulled out.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decoratePlans(collect([$plan]), $user)
            ->first();

        $this->assertNull($decorated->partner_price);
        $this->assertNull($decorated->partner_tenant_name);
        $this->assertTrue($decorated->is($plan), 'The plan must still be present in the collection.');
    }

    public function test_the_pricing_page_renders_the_partner_price_for_an_attributed_customer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->visiblePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(7900, app(CurrencyService::class)->getCurrency()->code));
    }

    public function test_the_pricing_page_shows_base_price_to_an_unattributed_customer(): void
    {
        $plan = $this->visiblePlan(basePrice: 4900);
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(4900, app(CurrencyService::class)->getCurrency()->code));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerStorefrontPricingTest`

Expected: FAIL — `Call to undefined method App\Services\PartnerPricingResolver::decoratePlans()`.

- [ ] **Step 3: Add `decoratePlans()` to the resolver**

Append to `app/Services/PartnerPricingResolver.php` (the `Illuminate\Support\Collection` import is already in the file's use list from Task 1):

```php
    /**
     * Stamp each plan with the price this buyer would actually pay.
     *
     * Sets two transient attributes — never persisted, because listing models
     * are read-only here:
     *   - partner_price: int|null, the partner's price, or null for base pricing
     *   - partner_tenant_name: string|null, for the "sold through" label
     *
     * A plan with no usable offering keeps partner_price = null and is left in
     * the collection at base price (spec §8.2, as amended 2026-09-05).
     *
     * @param  Collection<int, Plan>  $plans
     * @return Collection<int, Plan>
     */
    public function decoratePlans(Collection $plans, ?User $user = null): Collection
    {
        $tenant = $this->resolvePartnerTenant($user);

        foreach ($plans as $plan) {
            $price = $this->planPrice($user, $plan);

            $plan->partner_price = $price;
            $plan->partner_tenant_name = $price === null ? null : $tenant?->name;
        }

        return $plans;
    }

    /**
     * @param  Collection<int, OneTimeProduct>  $products
     * @return Collection<int, OneTimeProduct>
     */
    public function decorateProducts(Collection $products, ?User $user = null): Collection
    {
        $tenant = $this->resolvePartnerTenant($user);

        foreach ($products as $product) {
            $price = $this->productPrice($user, $product);

            $product->partner_price = $price;
            $product->partner_tenant_name = $price === null ? null : $tenant?->name;
        }

        return $products;
    }
```

(`decorateProducts()` is added here rather than in Task 4 so the resolver is edited once; Task 4 wires it up.)

- [ ] **Step 4: Decorate in the view component**

In `app/View/Components/Plans/All.php`, add `PartnerPricingResolver` to the constructor and decorate in `calculateViewData()`:

```php
    public function __construct(
        protected PlanService $planService,
        protected SubscriptionService $subscriptionService,
        protected PartnerPricingResolver $partnerPricingResolver,
        public array $products = [],
        public bool $isGrouped = true,
        public string $preselectedInterval = '',
        public bool $calculateSavingRates = false,
        public ?string $currentSubscriptionUuid = null,
        public bool $showDefaultProduct = false,
    ) {}
```

**The promoted-property order matters.** The injected service must come before the properties that carry Blade attribute values, otherwise `<x-plans.all calculate-saving-rates="true" show-default-product="1"/>` binds its attributes to the wrong parameters. Laravel resolves the leading type-hinted class parameters from the container and passes Blade attributes to the rest by name, so putting `$partnerPricingResolver` third — after the other two injected services and before `$products` — is correct.

Add `use App\Services\PartnerPricingResolver;` to the imports.

Then in `calculateViewData()`:

```php
    protected function calculateViewData()
    {
        $plans = $this->planService->getAllPlansWithPrices(
            $this->products,
            onlyVisible: true,
        );

        $plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());

        return $this->enrichViewData([], $plans);
    }
```

Leave `App\View\Components\Filament\Plans\All` alone. It renders the in-dashboard plan picker for an existing subscriber changing plans, which is a subscription *change* flow, not a new partner-priced purchase — and Plan 2 explicitly copies a renewal's price from the subscription rather than the current catalog (§6.6).

- [ ] **Step 5: Render the partner price in the plan card**

In `resources/views/components/plans/one.blade.php`, replace the price block. The current lines are:

```blade
        @if($price !== null)
            <div class="text-4xl">
                @money($price->price, $price->currency->code)
            </div>
```

Replace with:

```blade
        @if($price !== null)
            @php
                $effectivePrice = $plan->partner_price ?? $price->price;
            @endphp
            <div class="text-4xl">
                @money($effectivePrice, $price->currency->code)
            </div>
            @if($plan->partner_price !== null && $plan->partner_tenant_name !== null)
                <div class="text-xs text-neutral-400">
                    {{ __('Sold through :partner', ['partner' => $plan->partner_tenant_name]) }}
                </div>
            @endif
```

Do not touch the setup-fee, seat-based or usage-based blocks further down the file. Partner pricing is `flat_rate`-only (spec §2, enforced by `OfflineProvider::supportsPlan()`), so those branches are unreachable for a partner-priced plan and adding `partner_price` handling to them would be dead code.

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerStorefrontPricingTest`

Expected: PASS — 4 tests.

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Services/PartnerPricingResolver.php backend/app/View/Components/Plans/All.php backend/resources/views/components/plans/one.blade.php backend/tests/Feature/Services/PartnerStorefrontPricingTest.php
git commit -m "feat(partner): show partner plan pricing on the storefront"
```

---

### Task 4: Storefront product cards show the partner price

**Files:**
- Modify: `app/View/Components/Products/All.php`
- Modify: `resources/views/components/products/all.blade.php`
- Test: `tests/Feature/Services/PartnerStorefrontPricingTest.php` (extend the file from Task 3)

**Interfaces:**
- Consumes: `PartnerPricingResolver::decorateProducts(Collection $products, ?User $user = null): Collection` — already written in Task 3 Step 3.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Services/PartnerStorefrontPricingTest.php`, and add `use App\Models\OneTimeProduct;`, `use App\Models\OneTimeProductPrice;` and `use App\Models\PartnerProductOffering;` to its imports:

```php
    private function visibleProduct(int $basePrice = 4900): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        return $product;
    }

    public function test_a_configured_product_is_decorated_with_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct();
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decorateProducts(collect([$product]), $user)
            ->first();

        $this->assertSame(7900, $decorated->partner_price);
        $this->assertSame($partnerTenant->name, $decorated->partner_tenant_name);
    }

    public function test_an_unconfigured_product_stays_in_the_catalog_at_base_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $decorated = app(PartnerPricingResolver::class)
            ->decorateProducts(collect([$product]), $user)
            ->first();

        $this->assertNull($decorated->partner_price);
        $this->assertTrue($decorated->is($product));
    }

    public function test_the_pricing_page_renders_the_partner_product_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct();
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 8900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertOk();
        $response->assertSee(money(8900, app(CurrencyService::class)->getCurrency()->code));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerStorefrontPricingTest`

Expected: FAIL — `test_the_pricing_page_renders_the_partner_product_price` does not see `$89.00`; the decoration tests may already pass because `decorateProducts()` exists from Task 3, which is fine. Only the render test must fail here.

- [ ] **Step 3: Decorate in the product view component**

Replace `app/View/Components/Products/All.php` entirely:

```php
<?php

namespace App\View\Components\Products;

use App\Services\OneTimeProductService;
use App\Services\PartnerPricingResolver;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class All extends Component
{
    public function __construct(
        private OneTimeProductService $productService,
        private PartnerPricingResolver $partnerPricingResolver,
        private string $sortBy = 'name',
        private string $sortDirection = 'asc',
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.products.all', $this->calculateViewData());
    }

    protected function calculateViewData()
    {
        $products = $this->productService->getAllProductsWithPrices($this->sortBy, $this->sortDirection, true);

        return [
            'products' => $this->partnerPricingResolver->decorateProducts($products, auth()->user()),
        ];
    }
}
```

- [ ] **Step 4: Render the partner price in the product card**

In `resources/views/components/products/all.blade.php`, replace this block:

```blade
            <div class="text-center mx-auto">
                <p class="mt-6">
                    <span class="ms-1 text-primary-500 text-2xl font-bold">@money($price->price, $price->currency->code)</span>
                </p>
```

with:

```blade
            <div class="text-center mx-auto">
                <p class="mt-6">
                    <span class="ms-1 text-primary-500 text-2xl font-bold">@money($product->partner_price ?? $price->price, $price->currency->code)</span>
                </p>

                @if($product->partner_price !== null && $product->partner_tenant_name !== null)
                    <p class="text-xs text-neutral-400">
                        {{ __('Sold through :partner', ['partner' => $product->partner_tenant_name]) }}
                    </p>
                @endif
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerStorefrontPricingTest`

Expected: PASS — 7 tests.

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/View/Components/Products/All.php backend/resources/views/components/products/all.blade.php backend/tests/Feature/Services/PartnerStorefrontPricingTest.php
git commit -m "feat(partner): show partner product pricing on the storefront"
```

---
### Task 5: `calculatePlanTotals()` charges the partner price

**Files:**
- Modify: `app/Services/CalculationService.php`
- Test: `tests/Feature/Services/PartnerCheckoutPricingTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::planPrice(?User $user, Plan $plan): ?int` from Task 1.
- Produces: no signature change. `calculatePlanTotals(?User $user, string $planSlug, ?string $discountCode = null, ?int $quantity = 1, string $actionType = DiscountConstants::ACTION_TYPE_ANY): TotalsDto` keeps its exact signature — it already receives the `?User` this needs.

**Where the substitution goes.** Only in the `flat_rate` branch. `calculatePlanTotals()` has three subtotal branches: seat-based-with-included-seats, seat-based, and the plain `else`. Partner pricing is `flat_rate`-only (spec §2), so only the `else` branch is reachable for a partner-priced plan. The setup fee is read off the base `PlanPrice` and is left alone — a partner sets one price, not a price and a setup fee (spec §5.3), so the base setup fee continues to apply on top.

**`getPlanPrice()` is not touched.** The method still returns the base `PlanPrice` model. The substitution happens on `$totalsDto->subtotal` only. This is decision 2 and it is load-bearing: `StripeProvider:571`, `PaddleProvider:259`, `PolarProvider:431` and friends call `getPlanPrice()` to create gateway price objects.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/PartnerCheckoutPricingTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CalculationService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerCheckoutPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function flatRatePlan(int $basePrice = 4900): Plan
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
            'setup_fee' => 0,
        ]);

        return $plan;
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_an_attributed_buyer_is_charged_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(7900, $totals->subtotal);
        $this->assertSame(7900, $totals->amountDue);
    }

    public function test_an_unattributed_buyer_is_charged_the_base_price(): void
    {
        $plan = $this->flatRatePlan(basePrice: 4900);
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(4900, $totals->subtotal);
    }

    public function test_an_unconfigured_plan_is_charged_at_base_price_for_an_attributed_buyer(): void
    {
        // Spec §8.2 as amended.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $totals = app(CalculationService::class)->calculatePlanTotals($user, $plan->slug);

        $this->assertSame(11900, $totals->subtotal);
    }

    public function test_get_plan_price_still_returns_the_base_price_for_gateway_sync(): void
    {
        // Decision 2: five gateway providers depend on this returning base.
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan(basePrice: 4900);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $this->actingAs($this->attributedUser($partnerTenant));
        app(PartnerPricingResolver::class)->flush();

        $planPrice = app(CalculationService::class)->getPlanPrice($plan);

        $this->assertSame(4900, (int) $planPrice->price);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCheckoutPricingTest`

Expected: FAIL on `test_an_attributed_buyer_is_charged_the_partner_price` — `Failed asserting that 4900 is identical to 7900`.

- [ ] **Step 3: Substitute the partner price into the subtotal**

In `app/Services/CalculationService.php`, add the resolver to the constructor:

```php
    public function __construct(
        private PlanService $planService,
        private DiscountService $discountService,
        private OneTimeProductService $oneTimeProductService,
        private CurrencyService $currencyService,
        private PartnerPricingResolver $partnerPricingResolver,
    ) {}
```

Add `use App\Services\PartnerPricingResolver;` — or, since `CalculationService` is itself in `App\Services`, no import is needed; reference it by bare name and let Pint settle the file's style.

In `calculatePlanTotals()`, change the plain `else` branch. The current lines are:

```php
        } else {
            $totalsDto->subtotal = $planPrice->price;
        }
```

Replace with:

```php
        } else {
            // A partner-attributed buyer pays the partner's price for a flat-rate
            // plan (spec §8.1). Re-derived here from the resolver, never taken
            // from the request, which is what makes it untamperable. The base
            // $planPrice object is left untouched — gateway product sync reads it.
            $totalsDto->subtotal = $this->partnerPricingResolver->planPrice($user, $plan) ?? $planPrice->price;
        }
```

Everything after the branch — the discount, `amountDue`, `planPriceType`, `pricePerUnit`, `tiers` — is unchanged. A discount code still applies on top of the partner price, which is the existing `amountDue` formula doing its job.

**Do not** change `calculateNewPlanTotals()`. That is the plan-change/upgrade path for an existing subscription, and Plan 2 §6.6 already fixes a subscription's price to its own snapshot rather than the current catalog.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCheckoutPricingTest`

Expected: PASS — 4 tests.

- [ ] **Step 5: Run the existing calculation suite for regressions**

Run: `docker compose exec laravel.test php artisan test --filter=CalculationServiceTest`

Expected: PASS, unchanged count. These tests have no partner attribution, so `planPrice()` returns `null` and the `??` falls through to base.

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Services/CalculationService.php backend/tests/Feature/Services/PartnerCheckoutPricingTest.php
git commit -m "feat(partner): charge the partner price for flat-rate plan checkout"
```

---

### Task 6: Product cart and order totals charge the partner price

**Files:**
- Modify: `app/Services/CalculationService.php`
- Test: `tests/Feature/Services/PartnerCheckoutPricingTest.php` (extend the file from Task 5)

**Interfaces:**
- Consumes: `PartnerPricingResolver::productPrice(?User $user, OneTimeProduct $product): ?int` from Task 1.
- Produces: no signature changes to `calculateCartTotals(CartDto $cart, ?User $user): TotalsDto` or `calculateOrderTotals(Order $order, User $user, ?string $discountCode = null)`.

**Both methods, not one.** `calculateCartTotals()` is what the checkout form displays (`ProductCheckoutForm:78`, `:127`, `ProductTotals:128`). `calculateOrderTotals()` is what actually writes `order_items.price_per_unit`, `orders.total_amount` and `orders.total_amount_after_discount` — it is called from `OrderService::updateOrderProducts():207`. Changing only the first quotes a price the order does not carry; changing only the second charges a price the customer never saw. This is decision 8.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Services/PartnerCheckoutPricingTest.php`, adding these imports: `use App\Dto\CartDto;`, `use App\Dto\CartItemDto;`, `use App\Models\OneTimeProduct;`, `use App\Models\OneTimeProductPrice;`, `use App\Models\PartnerProductOffering;`.

Before writing, open `app/Dto/CartDto.php` and confirm the property names and the shape of `$cart->items` entries (the loop in `calculateCartTotals()` reads `$item->productId` and `$item->quantity`). Build the DTO the way the existing `tests/Feature/Services/CalculationServiceTest.php` builds it — copy that construction rather than guessing.

```php
    private function visibleProduct(int $basePrice = 4900): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'max_quantity' => 1,
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        return $product;
    }

    public function test_cart_totals_use_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 4900);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        // Build $cart exactly the way CalculationServiceTest builds it.
        $cart = new CartDto;
        $item = new CartItemDto;
        $item->productId = $product->id;
        $item->quantity = 1;
        $cart->items = [$item];

        $totals = app(CalculationService::class)->calculateCartTotals($cart, $user);

        $this->assertSame(7900, $totals->subtotal);
        $this->assertSame(7900, $totals->amountDue);
    }

    public function test_cart_totals_fall_back_to_base_for_an_unconfigured_product(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 11900);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $cart = new CartDto;
        $item = new CartItemDto;
        $item->productId = $product->id;
        $item->quantity = 1;
        $cart->items = [$item];

        $totals = app(CalculationService::class)->calculateCartTotals($cart, $user);

        $this->assertSame(11900, $totals->subtotal);
    }

    public function test_order_totals_write_the_partner_price_onto_the_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = $this->visibleProduct(basePrice: 4900);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        $customerTenant = $this->createTenant();
        $customerTenant->users()->attach($user);
        app(PartnerPricingResolver::class)->flush();

        $order = \App\Models\Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $customerTenant->id,
            'status' => \App\Constants\OrderStatus::NEW->value,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'price_per_unit' => 0,
        ]);
        $order->refresh();

        app(CalculationService::class)->calculateOrderTotals($order, $user);

        $this->assertSame(7900, (int) $order->fresh()->total_amount);
        $this->assertSame(7900, (int) $order->items()->first()->price_per_unit);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCheckoutPricingTest`

Expected: FAIL on `test_cart_totals_use_the_partner_price` and `test_order_totals_write_the_partner_price_onto_the_order`.

- [ ] **Step 3: Substitute in `calculateCartTotals()`**

In `app/Services/CalculationService.php`, inside the `foreach ($cart->items as $item)` loop, the current lines are:

```php
            $product = $this->oneTimeProductService->getOneTimeProductById($item->productId);
            $productPrice = $product->prices()->where('currency_id', $currency->id)->firstOrFail();

            $totalAmount += $productPrice->price * $item->quantity;

            $itemDiscountedPrice = $productPrice->price;
```

Replace with:

```php
            $product = $this->oneTimeProductService->getOneTimeProductById($item->productId);
            $productPrice = $product->prices()->where('currency_id', $currency->id)->firstOrFail();

            // Partner price when the buyer has a usable offering, base otherwise
            // (spec §8.1). Re-derived, never taken from the cart.
            $unitPrice = $this->partnerPricingResolver->productPrice($user, $product) ?? (int) $productPrice->price;

            $totalAmount += $unitPrice * $item->quantity;

            $itemDiscountedPrice = $unitPrice;
```

and, further down in the same loop, change the discount line from `$productPrice->price` to `$unitPrice`:

```php
            if ($discountCode !== null && $this->discountService->isCodeRedeemableForOneTimeProduct($discountCode, $user, $product)) {
                $discountAmount = $this->discountService->getDiscountAmount($discountCode, $unitPrice);
                $itemDiscountedPrice = max(0, $unitPrice - $discountAmount);
            }
```

- [ ] **Step 4: Substitute in `calculateOrderTotals()`**

In the same file, inside the `foreach ($orderItems as $orderItem)` loop, the current line is:

```php
            $orderItem->price_per_unit = $productPrice->price;
```

Replace with:

```php
            // Same rule as calculateCartTotals: this is the write side, and the
            // two must agree or the customer is quoted one price and charged another.
            $orderItem->price_per_unit = $this->partnerPricingResolver->productPrice($user, $product) ?? (int) $productPrice->price;
```

The rest of that loop already derives everything from `$orderItem->price_per_unit`, so no other line changes.

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCheckoutPricingTest`

Expected: PASS — 7 tests.

- [ ] **Step 6: Run the order and calculation suites for regressions**

Run: `docker compose exec laravel.test php artisan test --filter='CalculationServiceTest|OrderServiceTest|CashOrderCreationTest'`

Expected: PASS, unchanged counts.

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Services/CalculationService.php backend/tests/Feature/Services/PartnerCheckoutPricingTest.php
git commit -m "feat(partner): charge the partner price for one-time product checkout"
```

---

### Task 7: Partner-priced checkout offers the Offline provider only

**Files:**
- Modify: `app/Livewire/Checkout/SubscriptionCheckoutForm.php`
- Modify: `app/Livewire/Checkout/ProductCheckoutForm.php`
- Test: `tests/Feature/Livewire/Checkout/PartnerPaymentProviderRestrictionTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::usablePlanOffering()`, `usableProductOffering()` from Task 1; `PaymentService::getActivePaymentProvidersForPlan(Plan $plan, bool $shouldSupportSkippingTrial = false, bool $isNewPayment = false, bool $shouldSupportSeatBasedWithIncludedSeats = false, bool $shouldSupportSetupFees = false): array` and `PaymentService::getActivePaymentProvidersForOneTimePurchase(bool $requireQuantitySupport = false, bool $isNewPayment = false): array` (both existing).
- Produces: no new public API. The two `getPaymentProviders*` methods keep returning `array` of payment-provider interface instances.

**Why filter rather than query.** `PaymentService` builds its list from the `payment_providers` table and each provider's `supportsPlan()`. Adding a partner concept to `PaymentService` would push partner logic into the payment layer, which nothing else there needs. Filtering the returned array in the two checkout components keeps the rule where the partner price is being quoted.

**The empty-list case cannot happen.** If a usable offering exists, `PartnerPricingResolver` has already confirmed the Offline provider row is active (decision 5), so the filtered list always contains Offline. The existing `NoPaymentProvidersAvailableException` guard stays as-is for the genuinely-no-providers case.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Livewire/Checkout/PartnerPaymentProviderRestrictionTest.php`:

```php
<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use App\Services\PaymentProviders\PaymentService;
use Tests\Feature\FeatureTest;

class PartnerPaymentProviderRestrictionTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true]);
        PaymentProvider::where('slug', PaymentProviderConstants::STRIPE_SLUG)
            ->update(['is_active' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function flatRatePlan(int $basePrice = 4900): Plan
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
            'setup_fee' => 0,
        ]);

        return $plan;
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    /**
     * @param  array<int, object>  $providers
     * @return array<int, string>
     */
    private function slugs(array $providers): array
    {
        return array_map(fn ($provider): string => $provider->getSlug(), $providers);
    }

    public function test_a_partner_priced_plan_offers_only_the_offline_provider(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan();
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = \App\Livewire\Checkout\SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertGreaterThan(1, count($all), 'Arrangement failure: expected more than one active provider.');
        $this->assertSame([PaymentProviderConstants::OFFLINE_SLUG], $this->slugs($filtered));
    }

    public function test_an_unconfigured_plan_keeps_the_full_provider_list(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->flatRatePlan();
        $user = $this->attributedUser($partnerTenant);
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = \App\Livewire\Checkout\SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertSame($this->slugs($all), $this->slugs($filtered));
    }

    public function test_an_unattributed_buyer_keeps_the_full_provider_list(): void
    {
        $plan = $this->flatRatePlan();
        $user = $this->createUser();
        app(PartnerPricingResolver::class)->flush();

        $all = app(PaymentService::class)->getActivePaymentProvidersForPlan($plan, isNewPayment: true);
        $filtered = \App\Livewire\Checkout\SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertSame($this->slugs($all), $this->slugs($filtered));
    }
}
```

If the Stripe provider row does not exist in the seeded testing database, substitute any other seeded gateway slug from `App\Constants\PaymentProviderConstants` — the test only needs *some* second active provider so that "only Offline survives" is a meaningful assertion. Check `database/seeders/` for what is seeded before assuming.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPaymentProviderRestrictionTest`

Expected: FAIL — `Call to undefined method App\Livewire\Checkout\SubscriptionCheckoutForm::restrictToPartnerProviders()`.

- [ ] **Step 3: Add the shared filter to `CheckoutForm`**

Both checkout components extend the same base — `SubscriptionCheckoutForm extends CheckoutForm` and `ProductCheckoutForm extends CheckoutForm` — so the helper goes on `CheckoutForm` once and both inherit it.

Add to `app/Livewire/Checkout/CheckoutForm.php`:

```php
    /**
     * A partner price is only ever payable in cash (spec §1, §8.1), so when a
     * usable offering is driving the price, Offline is the only provider we
     * may present. The resolver has already confirmed the Offline row is
     * active before returning an offering, so this never empties the list.
     *
     * @param  array<int, PaymentProviderInterface>  $providers
     * @return array<int, PaymentProviderInterface>
     */
    public static function restrictToPartnerProviders(array $providers, ?object $usableOffering): array
    {
        if ($usableOffering === null) {
            return $providers;
        }

        return array_values(array_filter(
            $providers,
            fn ($provider): bool => $provider->getSlug() === \App\Constants\PaymentProviderConstants::OFFLINE_SLUG,
        ));
    }
```

Add `use App\Services\PaymentProviders\PaymentProviderInterface;` and `use App\Constants\PaymentProviderConstants;` to the file's imports, and drop the inline FQCN on `PaymentProviderConstants::OFFLINE_SLUG` in the method body. `PaymentService::getActivePaymentProviders()` and its siblings return instances out of `getPaymentProviderInterfaceMap()`, all of which implement `App\Services\PaymentProviders\PaymentProviderInterface`.

- [ ] **Step 4: Apply it in the subscription checkout**

In `app/Livewire/Checkout/SubscriptionCheckoutForm.php`, in the method around line 174 that calls `getActivePaymentProvidersForPlan()`, wrap the assignment:

```php
        $this->paymentProviders = self::restrictToPartnerProviders(
            $paymentService->getActivePaymentProvidersForPlan(
                // ... keep every existing argument exactly as it is
            ),
            app(PartnerPricingResolver::class)->usablePlanOffering(auth()->user(), $plan),
        );
```

Read the existing call before editing — it passes several named arguments and a `$plan` that is resolved a few lines above. Preserve all of them verbatim; only the wrapping is new. Add `use App\Services\PartnerPricingResolver;` to the imports.

- [ ] **Step 5: Apply it in the product checkout**

In `app/Livewire/Checkout/ProductCheckoutForm.php`, in `getPaymentProvidersForProduct()`, the current line is:

```php
        $this->paymentProviders = $paymentService->getActivePaymentProvidersForOneTimePurchase($requireQuantitySupport, true);
```

Replace with:

```php
        $cartDto = $this->sessionService->getCartDto();
        $product = $this->productService->getOneTimeProductById($cartDto->items[0]->productId);

        $this->paymentProviders = self::restrictToPartnerProviders(
            $paymentService->getActivePaymentProvidersForOneTimePurchase($requireQuantitySupport, true),
            app(PartnerPricingResolver::class)->usableProductOffering(auth()->user(), $product),
        );
```

`$this->sessionService` and `$this->productService` are already properties on this component — `render()` uses both. Add `use App\Services\PartnerPricingResolver;` to the imports. `restrictToPartnerProviders()` is inherited from `CheckoutForm`, so `self::` resolves correctly here.

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPaymentProviderRestrictionTest`

Expected: PASS — 3 tests.

- [ ] **Step 7: Run the checkout suite for regressions**

Run: `docker compose exec laravel.test php artisan test --filter='Checkout'`

Expected: PASS, unchanged counts. No existing checkout test has partner attribution, so `restrictToPartnerProviders()` receives `null` and returns the list untouched.

- [ ] **Step 8: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Livewire/Checkout/CheckoutForm.php backend/app/Livewire/Checkout/SubscriptionCheckoutForm.php backend/app/Livewire/Checkout/ProductCheckoutForm.php backend/tests/Feature/Livewire/Checkout/PartnerPaymentProviderRestrictionTest.php
git commit -m "feat(partner): restrict partner-priced checkout to the offline provider"
```

---
### Task 8: Admin order reporting — partner identity, margin, and history

**Files:**
- Modify: `app/Filament/Admin/Resources/Orders/OrderResource.php`
- Test: `tests/Feature/Filament/Admin/OrderPartnerReportingTest.php`

**Interfaces:**
- Consumes: `Order::partnerTenant(): BelongsTo`, `Order::approval(): HasOne`, `Order::user(): BelongsTo`, and the `orders.base_price_snapshot` / `quota_snapshot` / `partner_tenant_id` columns (all from Plan 2); `User::partner_attributed_at` / `partner_attribution_source` (Plan 1); `OrderApprovalService::amountDue(Order $order): int` (Plan 2).
- Produces: no new API. Filament schema changes only.

**Reuse the one amount-due rule.** Do not recompute amount due here. `orders.total_amount_after_discount` is `NOT NULL DEFAULT 0`, so the obvious `$order->total_amount_after_discount ?? $order->total_amount` misreads a paid cash order as comped. `OrderApprovalService::amountDue()` is the single correct rule for the whole feature — `PartnerOrderApprovalResource` already calls it, and so must this.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Admin/OrderPartnerReportingTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class OrderPartnerReportingTest extends FeatureTest
{
    private function partnerSoldOrder(): array
    {
        $partnerTenant = $this->createTenant();
        $customerTenant = $this->createTenant();

        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now()->subDays(3),
            'partner_attribution_source' => 'link',
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => true,
            'total_amount' => 7900,
            'total_amount_after_discount' => 7900,
            'base_price_snapshot' => 4900,
        ]);

        OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::PARTNER->value,
            'actor_user_id' => $customer->id,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => 'Cash received in person.',
            'decided_at' => now()->subDay(),
        ]);

        return [$order, $partnerTenant, $customer];
    }

    public function test_the_admin_view_page_shows_partner_identity_base_price_and_margin(): void
    {
        [$order, $partnerTenant] = $this->partnerSoldOrder();
        $this->actingAs($this->createAdminUser());

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee($partnerTenant->name)
            ->assertSee(money(4900, $order->currency->code))   // base price
            ->assertSee(money(3000, $order->currency->code));  // margin: 7900 - 4900
    }

    public function test_the_admin_view_page_shows_the_approval_and_attribution_history(): void
    {
        [$order] = $this->partnerSoldOrder();
        $this->actingAs($this->createAdminUser());

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee('Cash received in person.')
            ->assertSee('link');   // partner_attribution_source
    }

    public function test_a_direct_order_renders_without_partner_fields(): void
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => null,
            'status' => OrderStatus::SUCCESS->value,
            'total_amount' => 4900,
            'total_amount_after_discount' => 4900,
            'base_price_snapshot' => 4900,
        ]);

        $this->actingAs($this->createAdminUser());

        // The page must not blow up on a null partner — this is the majority case.
        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful();
    }
}
```

`ViewOrder` resolves its record by `uuid` (`Order::getRouteKeyName()` returns `'uuid'`), which is why `['record' => $order->uuid]` is passed rather than the id. Confirm against the existing admin order tests in `tests/Feature/Filament/Admin/` before running — if they use a different mount convention, copy theirs.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderPartnerReportingTest`

Expected: FAIL — the partner tenant name, base price and margin are not rendered.

- [ ] **Step 3: Add partner columns to the admin list table**

In `app/Filament/Admin/Resources/Orders/OrderResource.php`, inside `table()`, add after the existing `TextColumn::make('tenant.name')` column:

```php
                TextColumn::make('partnerTenant.name')
                    ->label(__('Sold Through'))
                    ->placeholder('—')
                    ->searchable(),
```

- [ ] **Step 4: Add the partner section to the infolist**

In the same file, inside the `Tab::make(__('Details'))->schema([...])` array, after the `Section::make(__('Order Details'))` section and before `Section::make(__('Order Items'))`, add:

```php
                                Section::make(__('Partner Sale'))
                                    ->description(__('Reseller pricing and margin for this order.'))
                                    ->visible(fn (Order $record): bool => $record->partner_tenant_id !== null)
                                    ->schema([
                                        TextEntry::make('partnerTenant.name')->label(__('Partner')),
                                        TextEntry::make('base_price_snapshot')
                                            ->label(__('Base Price'))
                                            ->getStateUsing(fn (Order $record): string => $record->base_price_snapshot === null
                                                ? '—'
                                                : money((int) $record->base_price_snapshot, $record->currency->code)),
                                        TextEntry::make('partner_price')
                                            ->label(__('Partner Price'))
                                            ->getStateUsing(fn (Order $record, OrderApprovalService $service): string => money(
                                                $service->amountDue($record),
                                                $record->currency->code,
                                            )),
                                        TextEntry::make('partner_margin')
                                            ->label(__('Margin'))
                                            ->getStateUsing(function (Order $record, OrderApprovalService $service): string {
                                                if ($record->base_price_snapshot === null) {
                                                    return '—';
                                                }

                                                return money(
                                                    $service->amountDue($record) - (int) $record->base_price_snapshot,
                                                    $record->currency->code,
                                                );
                                            }),
                                        TextEntry::make('quota_snapshot')
                                            ->label(__('Quotas Sold'))
                                            ->getStateUsing(function (Order $record): string {
                                                $quotas = (array) ($record->quota_snapshot ?? []);

                                                if ($quotas === []) {
                                                    return '—';
                                                }

                                                return collect($quotas)
                                                    ->map(fn ($value, string $key): string => $key.': '.$value)
                                                    ->implode(', ');
                                            }),
                                    ])->columns(3),
                                Section::make(__('Approval & Attribution History'))
                                    ->description(__('Who approved this order, and how the customer was attributed.'))
                                    ->visible(fn (Order $record): bool => $record->approval !== null || $record->partner_tenant_id !== null)
                                    ->schema([
                                        TextEntry::make('approval.decision')
                                            ->label(__('Decision'))
                                            ->placeholder('—')
                                            ->badge(),
                                        TextEntry::make('approval.actor_type')
                                            ->label(__('Decided By'))
                                            ->placeholder('—'),
                                        TextEntry::make('approval.actorUser.email')
                                            ->label(__('Acting User'))
                                            ->placeholder(__('System')),
                                        TextEntry::make('approval.decided_at')
                                            ->label(__('Decided At'))
                                            ->placeholder('—')
                                            ->dateTime(config('app.datetime_format')),
                                        TextEntry::make('approval.note')
                                            ->label(__('Internal Note'))
                                            ->placeholder('—')
                                            ->columnSpanFull(),
                                        TextEntry::make('user.partner_attribution_source')
                                            ->label(__('Attribution Source'))
                                            ->placeholder('—'),
                                        TextEntry::make('user.partner_attributed_at')
                                            ->label(__('Attributed At'))
                                            ->placeholder('—')
                                            ->dateTime(config('app.datetime_format')),
                                    ])->columns(3),
```

Add `use App\Services\CashPayments\OrderApprovalService;` to the file's imports. `TextEntry`, `Section` and `Order` are already imported.

Both sections are `->visible(...)`-gated so a direct (non-partner) order — the overwhelming majority — renders exactly as it does today.

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=OrderPartnerReportingTest`

Expected: PASS — 3 tests.

- [ ] **Step 6: Run the admin order suite for regressions**

Run: `docker compose exec laravel.test php artisan test --filter='Admin'`

Expected: PASS, unchanged counts.

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Admin/Resources/Orders/OrderResource.php backend/tests/Feature/Filament/Admin/OrderPartnerReportingTest.php
git commit -m "feat(partner): add partner sale and approval history reporting to admin orders"
```

---

### Task 9: Customer order view shows who sold it — and never the margin

**Files:**
- Modify: `app/Filament/Dashboard/Resources/Orders/OrderResource.php`
- Test: `tests/Feature/Filament/Dashboard/CustomerOrderPartnerVisibilityTest.php`

**Interfaces:**
- Consumes: `Order::partnerTenant(): BelongsTo` (Plan 2).
- Produces: no new API.

**This task's real deliverable is an absence.** Spec §10 as amended: the customer sees what they paid and who they bought through. They must never see `base_price_snapshot` or a margin — that would show them their partner's markup. The negative assertions below are the point of the task, not decoration on it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Dashboard/CustomerOrderPartnerVisibilityTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class CustomerOrderPartnerVisibilityTest extends FeatureTest
{
    private function partnerSoldOrderForCustomer(): array
    {
        $partnerTenant = $this->createTenant();
        $partnerTenant->update(['name' => 'Acme Consulting '.uniqid()]);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        ], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'link',
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => true,
            'total_amount' => 7900,
            'total_amount_after_discount' => 7900,
            'base_price_snapshot' => 4900,
        ]);

        return [$order, $partnerTenant, $customer, $customerTenant];
    }

    public function test_the_customer_sees_what_they_paid_and_who_sold_it(): void
    {
        [$order, $partnerTenant, $customer, $customerTenant] = $this->partnerSoldOrderForCustomer();
        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertSee($partnerTenant->name)
            ->assertSee(money(7900, $order->currency->code));
    }

    public function test_the_customer_never_sees_the_base_price_or_the_margin(): void
    {
        // Spec §10, as amended 2026-09-05: margin is partner/admin only.
        [$order, , $customer, $customerTenant] = $this->partnerSoldOrderForCustomer();
        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertDontSee(money(4900, $order->currency->code))   // base_price_snapshot
            ->assertDontSee(money(3000, $order->currency->code))   // margin
            ->assertDontSee('Base Price')
            ->assertDontSee('Margin');
    }

    public function test_a_direct_order_shows_no_partner_line(): void
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => null,
            'status' => OrderStatus::SUCCESS->value,
            'total_amount' => 4900,
            'total_amount_after_discount' => 4900,
        ]);

        $this->actingAs($customer);
        Filament::setTenant($customerTenant);

        Livewire::test(ViewOrder::class, ['record' => $order->uuid])
            ->assertSuccessful()
            ->assertDontSee('Sold Through');
    }
}
```

**On `assertDontSee` and money formatting:** `money(3000, 'USD')` renders as `$30.00`. If any unrelated number on the page happens to render identically, the assertion produces a confusing failure. If that happens, choose distinctive amounts in the arrangement (e.g. base `4237`, total `7911`) rather than weakening the assertion — the point is that these two specific numbers are absent.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=CustomerOrderPartnerVisibilityTest`

Expected: FAIL on `test_the_customer_sees_what_they_paid_and_who_sold_it` — the partner name is not rendered. The two negative tests should already pass, because nothing partner-related is shown yet; that is expected and correct. Do not skip them — they are the regression guard for Step 3.

- [ ] **Step 3: Add the "Sold Through" line only**

In `app/Filament/Dashboard/Resources/Orders/OrderResource.php`:

In `table()`, add after the existing `total_amount_after_discount` column:

```php
                TextColumn::make('partnerTenant.name')
                    ->label(__('Sold Through'))
                    ->placeholder('—'),
```

The column is unconditional and renders `—` for a direct order; only the infolist entry below is visibility-gated, because an empty entry in a detail section reads as a missing value rather than an absent concept.

In `infolist()`, inside the `Section::make(__('Order Details'))->schema([...])` array, add after the `TextEntry::make('total_amount')` entry:

```php
                                        TextEntry::make('partnerTenant.name')
                                            ->label(__('Sold Through'))
                                            ->visible(fn (Order $record): bool => $record->partner_tenant_id !== null),
```

**That is the whole change.** Do not add `base_price_snapshot`, do not add a margin entry, do not add `quota_snapshot`. If a future reviewer asks why this resource is thinner than the admin one, the answer is spec §10 as amended, and it is deliberate.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=CustomerOrderPartnerVisibilityTest`

Expected: PASS — 3 tests, including both negative assertions still holding.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Dashboard/Resources/Orders/OrderResource.php backend/tests/Feature/Filament/Dashboard/CustomerOrderPartnerVisibilityTest.php
git commit -m "feat(partner): show the selling partner on customer orders without the margin"
```

---

### Task 10: Restore customer dashboard navigation (§11)

**Files:**
- Modify: `app/Filament/Dashboard/Resources/Orders/OrderResource.php`
- Modify: `app/Filament/Dashboard/Resources/Subscriptions/SubscriptionResource.php`
- Modify: `app/Filament/Dashboard/Resources/Transactions/TransactionResource.php`
- Test: `tests/Feature/Filament/Dashboard/DashboardNavigationTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new.

**What this reverts.** Commit `24ce804` ("feat(dashboard): hide Orders, Subscriptions, and Transactions from customer nav") added exactly five lines to each of three files:

```php
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
```

**How the gating actually works in Filament 5.6** — verified against `vendor/filament/filament/src/Resources/Resource/Concerns/HasNavigation.php`, because it is easy to assume wrongly here:

- The default `shouldRegisterNavigation()` returns the static property `static::$shouldRegisterNavigation`, which is `true`. It is **not** an alias for `canAccess()`.
- `registerNavigationItems()` checks `shouldRegisterNavigation()` **and then separately checks `canAccess()`**, returning early if either is false.

So deleting the override does not weaken any gate. `canAccess()` on all three resources already requires both `config('app.customer_dashboard.show_*')` and the corresponding tenant permission, and it is still consulted on every navigation render. Nothing becomes reachable that a permitted user could not already reach by direct URL — which is exactly what `24ce804`'s own commit message noted about the override's limits.

**Do not touch `AuditRequestResource`.** It also defines `shouldRegisterNavigation()`, but with a real conditional body that predates `24ce804` and is unrelated to §11.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Dashboard/DashboardNavigationTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Dashboard\Resources\Transactions\TransactionResource;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class DashboardNavigationTest extends FeatureTest
{
    public function test_a_permitted_customer_sees_orders_subscriptions_and_transactions_in_the_nav(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', true);
        config()->set('app.customer_dashboard.show_subscriptions', true);
        config()->set('app.customer_dashboard.show_transactions', true);

        // shouldRegisterNavigation() proves the hardcoded override is gone.
        $this->assertTrue(OrderResource::shouldRegisterNavigation());
        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
        $this->assertTrue(TransactionResource::shouldRegisterNavigation());

        // canAccess() is the second gate registerNavigationItems() consults;
        // both must pass for the sidebar item to appear, so assert both.
        $this->assertTrue(OrderResource::canAccess());
        $this->assertTrue(SubscriptionResource::canAccess());
        $this->assertTrue(TransactionResource::canAccess());
    }

    public function test_the_config_switch_still_hides_them(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', false);
        config()->set('app.customer_dashboard.show_subscriptions', false);
        config()->set('app.customer_dashboard.show_transactions', false);

        $this->assertFalse(OrderResource::canAccess());
        $this->assertFalse(SubscriptionResource::canAccess());
        $this->assertFalse(TransactionResource::canAccess());
    }

    public function test_a_customer_without_the_permissions_cannot_access_them(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, []);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', true);
        config()->set('app.customer_dashboard.show_subscriptions', true);
        config()->set('app.customer_dashboard.show_transactions', true);

        $this->assertFalse(OrderResource::canAccess());
        $this->assertFalse(SubscriptionResource::canAccess());
        $this->assertFalse(TransactionResource::canAccess());
    }
}
```

The first test asserts both gates because either one alone would be misleading: `shouldRegisterNavigation()` returning `true` is a near-tautology once the override is deleted (it just reads a static property), and `canAccess()` was already true before this task. Together they state the actual deliverable — the item now registers, and it registers for the right reason.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=DashboardNavigationTest`

Expected: FAIL on the first test — all three `shouldRegisterNavigation()` return the hardcoded `false`.

- [ ] **Step 3: Delete the three overrides**

In each of the three files, delete this exact block, and the blank line that follows it:

```php
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
```

- `app/Filament/Dashboard/Resources/Orders/OrderResource.php` (immediately after the `$navigationIcon` property)
- `app/Filament/Dashboard/Resources/Subscriptions/SubscriptionResource.php`
- `app/Filament/Dashboard/Resources/Transactions/TransactionResource.php`

Confirm with `git diff` that exactly 15 lines were removed across three files and nothing else changed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=DashboardNavigationTest`

Expected: PASS — 3 tests.

- [ ] **Step 5: Run the dashboard suite for regressions**

Run: `docker compose exec laravel.test php artisan test --filter='Dashboard'`

Expected: PASS, unchanged counts.

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
git add backend/app/Filament/Dashboard/Resources/Orders/OrderResource.php backend/app/Filament/Dashboard/Resources/Subscriptions/SubscriptionResource.php backend/app/Filament/Dashboard/Resources/Transactions/TransactionResource.php backend/tests/Feature/Filament/Dashboard/DashboardNavigationTest.php
git commit -m "feat(dashboard): restore Orders, Subscriptions and Transactions navigation

Reverts the shouldRegisterNavigation() => false overrides added in 24ce804,
per spec §11. canAccess() on all three already gates on both the
customer_dashboard config switch and the tenant permission, so nothing is
exposed that a permitted user could not already reach by direct URL."
```

---

### Task 11: Full regression pass and spec reconciliation

**Files:** none modified unless a gate fails.

**Interfaces:** none.

- [ ] **Step 1: Run the whole suite**

Run: `docker compose exec laravel.test php artisan test --compact`

Expected: PASS with zero failures. The Plan 2 baseline at commit `f96f112` was **1374 passed, 3429 assertions**. This plan adds roughly 30 tests across seven new files, so expect approximately 1404 passing and no failures. A *failure* is a regression to fix; a differing *count* is not, on its own, a problem — but reconcile it against the seven new test files before moving on.

- [ ] **Step 2: Run the formatting and static analysis gates**

```bash
docker compose exec laravel.test vendor/bin/pint --test
docker compose exec laravel.test vendor/bin/phpstan analyse
```

Expected: Pint clean. PHPStan reports exactly **one** error, the pre-existing one in `app/Services/AuditGroupDeltaService.php`. Any second error is yours — fix it.

- [ ] **Step 3: Verify the gateway path was not disturbed**

This is the one failure mode that the test suite is least likely to catch on its own, because gateway product sync is exercised through provider integration paths rather than unit assertions. Confirm by inspection:

```bash
git diff --stat main...HEAD -- backend/app/Services/PaymentProviders/
```

Expected: **empty output**. No file under `app/Services/PaymentProviders/` may have changed in this plan. If anything appears there, decision 2 was violated — stop and report it.

Then confirm the two price accessors still return base prices:

```bash
docker compose exec laravel.test php artisan test --filter=test_get_plan_price_still_returns_the_base_price_for_gateway_sync
```

Expected: PASS.

- [ ] **Step 4: Reconcile the plan against the amended spec**

Re-read §8, §10 and §11 of `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md`, **including the amendment notes at the top of §8.2 and §10**. Walk the Spec coverage table at the end of this plan and confirm each row points at work that actually landed. Report any row you cannot substantiate rather than quietly marking it done.

- [ ] **Step 5: Commit any fixes and report**

If Steps 1–4 required changes:

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add -A backend/
git commit -m "fix(partner): address regressions found in the Plan 3 full-suite pass"
```

Report the final suite numbers, the Pint result, the PHPStan error count, and the `git diff --stat` for `app/Services/PaymentProviders/`.

---

## Spec coverage

| Spec section | Covered by |
| --- | --- |
| §8.1 `PartnerPricingResolver` — attributed user and anonymous session code, honored only for an active Partner Plan | Task 1 |
| §8.1 Resolver used by the public pricing page and plan/product listing components | Tasks 3, 4 |
| §8.1 Checkout re-derives price server-side rather than trusting client-submitted values | Tasks 5, 6 (decision 9 — no price is ever read from the request) |
| §8.2 (as amended 2026-09-05) Unconfigured/disabled items stay in the catalog at base price; the partner is never handed an approval for them | Tasks 3, 4 (read side), Task 2 (write side, via `PurchaseSnapshotService` delegation) |
| §7.5 Partner Plan lapse stops partner pricing for new checkouts | Task 1 (`resolvePartnerTenant()` gates on `tenantIsActivePartner()`); asserted in `test_a_lapsed_partner_plan_yields_no_partner_price` |
| §5.5 Below-minimum offerings are blocked at read time | Task 1; asserted in `test_an_offering_below_the_live_minimum_yields_no_partner_price` |
| §1 / §2 Partner sales are cash-only; no gateway ever sees a partner price | Tasks 5, 7 (decision 2); verified in Task 11 Step 3 |
| §10 (as amended) Partner queue shows base, partner price and margin | Shipped in Plan 2, Task 12 — no work here |
| §10 (as amended) Admin Order resource: partner identity, base, partner price, margin, approval + attribution history | Task 8 |
| §10 (as amended) Customer Orders view shows amount paid and the selling partner, **never** base price or margin | Task 9 (negative assertions are the deliverable) |
| §11 Revert the `shouldRegisterNavigation()` overrides from `24ce804` | Task 10 |
| §13 Testing strategy | every task (TDD), Task 11 (gates) |

**Not covered here, and not deferred to a Plan 4 — genuinely out of scope per spec §2:** platform commission/settlement, multi-currency partner pricing, seat-based or usage-based cash plans, sub-reseller chains. With Plan 3 complete, spec §4–§11 are fully implemented and the Partner Reselling feature is done.
