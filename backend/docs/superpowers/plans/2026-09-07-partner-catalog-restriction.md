# Partner Catalog Restriction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A customer attributed to a partner sees and can buy only the plans/products that partner has enabled — everywhere the catalog appears (the authenticated `/pricing` page, the Filament dashboard's change-plan page) and at checkout — instead of falling back to the base-priced full catalog.

**Architecture:** `PartnerPricingResolver` gains two new filtering methods (`purchasablePlans()`/`purchasableProducts()`) alongside its existing price-substitution methods (`decoratePlans()`/`decorateProducts()`), which stay unchanged. The plan/product listing components call the new filter right before grouping; `CheckoutService`'s three purchase-creation methods call it again server-side, throwing a new `PurchaseNotAllowedException` the three consuming Livewire checkout forms catch. A pre-existing bug (the Filament change-plan page never applied partner pricing at all) is fixed first, since the new filter is only meaningful on a correctly-priced surface.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Larastan 3, Pint.

**Spec:** `backend/docs/superpowers/specs/2026-09-07-partner-catalog-restriction-design.md` (this plan argues from it; read it first). Builds on: `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md`, `backend/docs/superpowers/specs/2026-09-07-partner-attribution-and-packages-design.md`.

## Global Constraints

- All commands run from `backend/`. Tests: `docker compose exec laravel.test php artisan test --compact --filter=<Name>` (from the repo root `/var/www/html/flexpick.net` — the host cannot resolve the `mysql` DB host directly).
- The suite shares one database across test classes; never rely on a clean table, use `updateOrCreate`/`firstOrCreate` where a fixture needs a fixed slug.
- Formatting gate: `vendor/bin/pint` then `vendor/bin/pint --test` (plain, never `--dirty`) — must be clean.
- Static analysis: `vendor/bin/phpstan analyse` — exactly one pre-existing error is acceptable (`AuditGroupDeltaService.php:112`, `return.type`); no new ones. If a nullsafe chain (`?->`) through a relation triggers a new `property.notFound` error, use `optional($x)->y` instead — a known Larastan limitation already worked around elsewhere in this codebase.
- `git add` explicit paths only, never `-A`/`.`.
- Money is stored in cents. "Usable offering" always means: `is_enabled = true` AND not below the live admin floor (`PartnerCatalogService::isPlanOfferingBelowMinimum()`/`isProductOfferingBelowMinimum()`) AND the buyer's partner tenant currently has an active Partner Plan — this is exactly what `PartnerPricingResolver::usablePlanOffering()`/`usableProductOffering()` already compute; the new filtering methods reuse them rather than re-deriving the rule.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_0111xJ3cJRSkcFiK97g3Kdzz
  ```

## File structure

**Created**
- `app/Exceptions/PurchaseNotAllowedException.php` — thrown by `CheckoutService` when an attributed buyer targets a non-enabled item.
- Tests listed per task.

**Modified**
- `app/View/Components/Filament/Plans/All.php` — bug fix: call `decoratePlans()`.
- `app/Services/PartnerPricingResolver.php` — add `purchasablePlans()`/`purchasableProducts()`.
- `app/View/Components/Plans/All.php` — filter before grouping.
- `resources/views/components/plans/all.blade.php`, `resources/views/components/filament/plans/all.blade.php` — empty-state block.
- `app/View/Components/Products/All.php` — filter.
- `resources/views/components/products/all.blade.php` — empty-state block.
- `app/Services/CheckoutService.php` — enforcement in `initSubscriptionCheckout()`, `initLocalSubscriptionCheckout()`, `initProductCheckout()`.
- `app/Livewire/Checkout/SubscriptionCheckoutForm.php`, `LocalSubscriptionCheckoutForm.php`, `ProductCheckoutForm.php` — catch the new exception.
- Tests listed per task.

---

### Task 1: Fix the Filament change-plan page's missing partner-price decoration

**Files:**
- Modify: `app/View/Components/Filament/Plans/All.php`
- Create: `tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::decoratePlans(Collection $plans, ?User $user = null): Collection` (existing, unchanged).
- Produces: the Filament dashboard's change-plan page now shows `partner_price`/`partner_tenant_name` on every rendered plan, matching the public pricing page.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class PartnerChangePlanPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    public function test_the_change_plan_page_shows_the_partner_price_not_the_base_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();

        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $customerTenant->id,
            'user_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['product_id' => Product::factory()->create()->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($customerTenant);

        $html = $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $customerTenant))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString((string) money(7900, 'USD'), $html);
        $this->assertStringNotContainsString((string) money(4900, 'USD'), $html);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PartnerChangePlanPricingTest`
Expected: FAIL — the response contains the base price (`$49.00`), not the partner price (`$79.00`), because `calculateViewData()` never decorates.

- [ ] **Step 3: Fix the component**

In `app/View/Components/Filament/Plans/All.php`, add the decoration call right before `enrichViewData()`:

```php
    protected function calculateViewData()
    {
        $subscription = null;
        if ($this->currentSubscriptionUuid !== null) {
            $subscription = $this->subscriptionService->findActiveByTenantAndSubscriptionUuid(Filament::getTenant(), $this->currentSubscriptionUuid);
        }

        $planType = null;
        if ($subscription !== null) {
            $planType = $subscription->plan->type;
        }

        $plans = $this->planService->getAllPlansWithPrices(
            $this->products,
            $planType,
            onlyVisible: true,
        );

        $plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());

        $viewData['subscription'] = $subscription;

        return $this->enrichViewData($viewData, $plans);
    }
```

(Only the new `$plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());` line is added; `$this->partnerPricingResolver` is already an inherited protected property from the parent class, no new constructor dependency needed.)

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PartnerChangePlanPricingTest`
Expected: PASS.

- [ ] **Step 5: Run the wider change-plan/subscription slice for regressions**

Run: `docker compose exec laravel.test php artisan test --compact --filter="PartnerChangePlanPricingTest|ChangeSubscriptionPlan|SubscriptionResource"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/View/Components/Filament/Plans/All.php tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php
git commit -m "fix(partner): decorate the Filament change-plan page with partner pricing"
```

---

### Task 2: `PartnerPricingResolver::purchasablePlans()` and `purchasableProducts()`

**Files:**
- Modify: `app/Services/PartnerPricingResolver.php`
- Modify: `tests/Feature/Services/PartnerPricingResolverTest.php`

**Interfaces:**
- Consumes: `resolvePartnerTenant(?User $user)`, `usablePlanOffering(?User $user, Plan $plan)`, `usableProductOffering(?User $user, OneTimeProduct $product)` (all existing, unchanged).
- Produces (used by Tasks 3, 4, 5):
  - `purchasablePlans(Collection<int, Plan> $plans, ?User $user = null): Collection<int, Plan>` — for a non-attributed buyer (or one whose partner has lapsed), returns `$plans` unchanged. For an attributed buyer, returns only the plans with a usable offering.
  - `purchasableProducts(Collection<int, OneTimeProduct> $products, ?User $user = null): Collection<int, OneTimeProduct>` — same rule for products.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Services/PartnerPricingResolverTest.php` (reuses the file's existing `activePartnerTenant()`, `sellablePlan()`, `attributedUser()`, `plantPartnerCookie()` helpers — add `use App\Models\OneTimeProduct;` and `use App\Models\OneTimeProductPrice;` and `use App\Models\PartnerProductOffering;` to the imports if not already present):

```php
    public function test_purchasable_plans_is_unfiltered_for_a_non_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$sellable] = $this->sellablePlan($partnerTenant);
        $plain = Plan::factory()->create(['is_active' => true, 'is_visible' => true]);
        $user = $this->createUser();

        $result = $this->resolver()->purchasablePlans(collect([$sellable, $plain]), $user);

        $this->assertTrue($result->contains($sellable));
        $this->assertTrue($result->contains($plain));
    }

    public function test_purchasable_plans_keeps_only_usable_offerings_for_an_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$sellable] = $this->sellablePlan($partnerTenant);
        $notConfigured = Plan::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);

        $result = $this->resolver()->purchasablePlans(collect([$sellable, $notConfigured]), $user);

        $this->assertTrue($result->contains($sellable));
        $this->assertFalse($result->contains($notConfigured));
    }

    public function test_purchasable_plans_drops_a_disabled_offering(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $offering] = $this->sellablePlan($partnerTenant);
        $offering->update(['is_enabled' => false]);
        $user = $this->attributedUser($partnerTenant);

        $result = $this->resolver()->purchasablePlans(collect([$plan]), $user);

        $this->assertTrue($result->isEmpty());
    }

    public function test_purchasable_products_is_unfiltered_for_a_non_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        $user = $this->createUser();

        $result = $this->resolver()->purchasableProducts(collect([$product]), $user);

        $this->assertTrue($result->contains($product));
    }

    public function test_purchasable_products_keeps_only_usable_offerings_for_an_attributed_buyer(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $sellable = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $sellable->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $sellable->id,
            'price' => 7900,
            'is_enabled' => true,
        ]);
        $notConfigured = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $notConfigured->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        $user = $this->attributedUser($partnerTenant);

        $result = $this->resolver()->purchasableProducts(collect([$sellable, $notConfigured]), $user);

        $this->assertTrue($result->contains($sellable));
        $this->assertFalse($result->contains($notConfigured));
    }

    public function test_purchasable_plans_is_unfiltered_when_the_partners_plan_has_lapsed(): void
    {
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->subDay(),
        ]);
        [$sellable] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $result = $this->resolver()->purchasablePlans(collect([$sellable]), $user);

        $this->assertTrue($result->contains($sellable));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PartnerPricingResolverTest`
Expected: FAIL on the six new tests — `purchasablePlans`/`purchasableProducts` undefined.

- [ ] **Step 3: Implement**

In `app/Services/PartnerPricingResolver.php`, add both methods after `decorateProducts()`:

```php
    /**
     * The list a purchasing surface should actually offer (spec §3, catalog
     * restriction): unchanged for a non-attributed buyer or one whose
     * partner's plan has lapsed; narrowed to only usable offerings for an
     * attributed buyer. Reuses usablePlanOffering() rather than re-deriving
     * "usable" — decoratePlans() and this method must never disagree about
     * which plans are resellable.
     *
     * @param  Collection<int, Plan>  $plans
     * @return Collection<int, Plan>
     */
    public function purchasablePlans(Collection $plans, ?User $user = null): Collection
    {
        if ($this->resolvePartnerTenant($user) === null) {
            return $plans;
        }

        return $plans->filter(fn (Plan $plan): bool => $this->usablePlanOffering($user, $plan) !== null)->values();
    }

    /**
     * @param  Collection<int, OneTimeProduct>  $products
     * @return Collection<int, OneTimeProduct>
     */
    public function purchasableProducts(Collection $products, ?User $user = null): Collection
    {
        if ($this->resolvePartnerTenant($user) === null) {
            return $products;
        }

        return $products->filter(fn (OneTimeProduct $product): bool => $this->usableProductOffering($user, $product) !== null)->values();
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PartnerPricingResolverTest`
Expected: PASS (all tests in the file, existing and new).

- [ ] **Step 5: Commit**

```bash
git add app/Services/PartnerPricingResolver.php tests/Feature/Services/PartnerPricingResolverTest.php
git commit -m "feat(partner): PartnerPricingResolver filters a buyer's purchasable plans and products"
```

---

### Task 3: Filter the plans catalog on both plan-listing surfaces, with an empty state

**Files:**
- Modify: `app/View/Components/Plans/All.php`
- Modify: `resources/views/components/plans/all.blade.php`, `resources/views/components/filament/plans/all.blade.php`
- Modify: `tests/Feature/Http/Controllers/PricingPageTest.php`
- Modify: `tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php` (created in Task 1)

**Interfaces:**
- Consumes: `PartnerPricingResolver::purchasablePlans()` (Task 2).
- Produces: `$plans` passed to both blade views is already filtered by the time `enrichViewData()` groups it, so `$tierSections`/`$groupedPlans` only ever contain purchasable items; a `$plans->isEmpty()` guard renders an empty-state message instead of the grid.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Controllers/PricingPageTest.php` (add `use App\Models\PartnerPlanOffering;` if not already imported — check the existing imports first, several of these are likely already present from earlier partner-pricing tests in this same file):

```php
    public function test_an_attributed_customer_sees_only_the_partners_enabled_plans(): void
    {
        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $enabledProduct = Product::factory()->create(['name' => 'Enabled Package']);
        $enabledPlan = Plan::factory()->create([
            'product_id' => $enabledProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $enabledPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $enabledPlan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $notConfiguredProduct = Product::factory()->create(['name' => 'Not Configured Package']);
        $notConfiguredPlan = Plan::factory()->create([
            'product_id' => $notConfiguredProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $notConfiguredPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 5900]);

        $customer = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get(route('pricing'));

        $response->assertSee('Enabled Package');
        $response->assertDontSee('Not Configured Package');
    }

    public function test_a_direct_customer_still_sees_every_visible_plan(): void
    {
        $product = Product::factory()->create(['name' => 'Any Product']);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $customer = $this->createUser();

        $response = $this->actingAs($customer)->get(route('pricing'));

        $response->assertSee('Any Product');
    }

    public function test_an_attributed_customer_with_nothing_enabled_sees_the_empty_state(): void
    {
        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $unconfiguredProduct = Product::factory()->create(['name' => 'Unconfigured Product']);
        $unconfiguredPlan = Plan::factory()->create([
            'product_id' => $unconfiguredProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $unconfiguredPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $customer = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get(route('pricing'));

        $response->assertDontSee('Unconfigured Product');
        $response->assertSee(__('Nothing available yet — check back soon.'));
    }
```

Append to `tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php` (created in Task 1; add `use App\Models\Subscription;` if not already imported — it already is, from Task 1's test):

```php
    public function test_the_change_plan_page_hides_a_not_configured_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();

        $enabledProduct = Product::factory()->create(['name' => 'Enabled On Dashboard']);
        $enabledPlan = Plan::factory()->create([
            'product_id' => $enabledProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $enabledPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $enabledPlan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $notConfiguredProduct = Product::factory()->create(['name' => 'Not Configured On Dashboard']);
        $notConfiguredPlan = Plan::factory()->create([
            'product_id' => $notConfiguredProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $notConfiguredPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 5900]);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $customerTenant->id,
            'user_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['product_id' => Product::factory()->create()->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($customerTenant);

        $html = $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $customerTenant))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('Enabled On Dashboard', $html);
        $this->assertStringNotContainsString('Not Configured On Dashboard', $html);
    }

    /**
     * Grandfathering (spec §7): the filter only narrows what's offered for a
     * NEW purchase or plan change. A customer's own already-active
     * subscription must keep rendering on its own detail page even after its
     * plan's partner offering is disabled — that page is a plain Filament
     * resource view, not the plans-listing component this task touches, so
     * this proves the two are genuinely independent.
     */
    public function test_an_existing_subscription_is_still_viewable_after_its_offering_is_disabled(): void
    {
        $partnerTenant = $this->activePartnerTenant();

        $product = Product::factory()->create(['name' => 'Since Disabled']);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);
        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $customerTenant->id,
            'user_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $offering->update(['is_enabled' => false]);

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($customerTenant);

        $this->get(SubscriptionResource::getUrl('view', ['record' => $subscription->uuid], tenant: $customerTenant))
            ->assertSuccessful();
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact --filter="PricingPageTest|PartnerChangePlanPricingTest"`
Expected: FAIL — the not-configured items still render, and the empty-state copy is missing.

- [ ] **Step 3: Implement the filter**

In `app/View/Components/Plans/All.php::calculateViewData()`, filter right after decorating, before `enrichViewData()`:

```php
    protected function calculateViewData()
    {
        $plans = $this->planService->getAllPlansWithPrices(
            $this->products,
            onlyVisible: true,
        );

        $plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());
        $plans = $this->partnerPricingResolver->purchasablePlans($plans, auth()->user());

        return $this->enrichViewData([], $plans);
    }
```

In `app/View/Components/Filament/Plans/All.php::calculateViewData()` (from Task 1), add the same second line after the decorate call:

```php
        $plans = $this->partnerPricingResolver->decoratePlans($plans, auth()->user());
        $plans = $this->partnerPricingResolver->purchasablePlans($plans, auth()->user());

        $viewData['subscription'] = $subscription;

        return $this->enrichViewData($viewData, $plans);
```

- [ ] **Step 4: Add the empty state to both blade views**

In `resources/views/components/plans/all.blade.php`, wrap the existing content in a top-level check — replace the file's opening (before `@isset($partnerName)`) with:

```blade
@if ($plans->isEmpty())
    <div class="fp-panel mx-auto max-w-2xl text-center" style="padding: 48px 32px;">
        <p class="m-0">{{ __('Nothing available yet — check back soon.') }}</p>
    </div>
@else
```

and add a matching `@endif` at the very end of the file (after the existing `@if (isset($defaultProduct)) ... @endif` block).

In `resources/views/components/filament/plans/all.blade.php`, wrap the `@if($isGrouped) ... @else ... @endif` block (everything after the `<section class="fp-plan-picker">` line and its "current plan" strip) the same way:

```blade
    @if ($plans->isEmpty())
        <div class="fp-panel mx-auto max-w-2xl text-center" style="padding: 48px 32px;">
            <p class="m-0">{{ __('Nothing available yet — check back soon.') }}</p>
        </div>
    @else
```

with the matching `@endif` added right before the existing closing `</section>` tag (after the current `@if($isGrouped) ... @else ... @endif` block).

- [ ] **Step 5: Run to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter="PricingPageTest|PartnerChangePlanPricingTest"`
Expected: PASS.

- [ ] **Step 6: Run the wider pricing/plans slice for regressions**

Run: `docker compose exec laravel.test php artisan test --compact --filter="PricingPageTest|PartnerChangePlanPricingTest|FpPlanCardComponentTest|DashboardMenuItemsTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/View/Components/Plans/All.php app/View/Components/Filament/Plans/All.php resources/views/components/plans/all.blade.php resources/views/components/filament/plans/all.blade.php tests/Feature/Http/Controllers/PricingPageTest.php tests/Feature/Filament/Dashboard/PartnerChangePlanPricingTest.php
git commit -m "feat(partner): filter both plan-listing surfaces to a referred customer's enabled catalog"
```

---

### Task 4: Filter the products catalog, with an empty state

**Files:**
- Modify: `app/View/Components/Products/All.php`
- Modify: `resources/views/components/products/all.blade.php`
- Modify: `tests/Feature/Http/Controllers/PricingPageTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::purchasableProducts()` (Task 2).
- Produces: `$products` passed to `components.products.all` is already filtered.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Controllers/PricingPageTest.php` (add `use App\Models\OneTimeProduct;`, `use App\Models\OneTimeProductPrice;`, `use App\Models\PartnerProductOffering;` if not already imported):

```php
    public function test_an_attributed_customer_sees_only_the_partners_enabled_products(): void
    {
        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $enabledOneTimeProduct = OneTimeProduct::factory()->create([
            'name' => 'Enabled One-Time Package',
            'is_active' => true,
            'is_visible' => true,
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $enabledOneTimeProduct->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $enabledOneTimeProduct->id,
            'price' => 7900,
            'is_enabled' => true,
        ]);

        $notConfiguredOneTimeProduct = OneTimeProduct::factory()->create([
            'name' => 'Not Configured One-Time Package',
            'is_active' => true,
            'is_visible' => true,
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $notConfiguredOneTimeProduct->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 5900,
        ]);

        $customer = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get(route('pricing'));

        $response->assertSee('Enabled One-Time Package');
        $response->assertDontSee('Not Configured One-Time Package');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PricingPageTest`
Expected: FAIL — the not-configured product still renders.

- [ ] **Step 3: Implement the filter**

In `app/View/Components/Products/All.php::calculateViewData()`:

```php
    protected function calculateViewData()
    {
        $products = $this->productService->getAllProductsWithPrices($this->sortBy, $this->sortDirection, true);

        $products = $this->partnerPricingResolver->decorateProducts($products, auth()->user());
        $products = $this->partnerPricingResolver->purchasableProducts($products, auth()->user());

        return [
            'products' => $products,
        ];
    }
```

- [ ] **Step 4: Add the empty state**

In `resources/views/components/products/all.blade.php`, wrap the `@foreach` in a check:

```blade
@inject('productService', 'App\Services\OneTimeProductService')

@if ($products->isEmpty())
    <p class="text-center" style="color: var(--fp-muted)">{{ __('Nothing available yet — check back soon.') }}</p>
@else
    <div {{ $attributes->merge(['class' => 'fp-plan-grid']) }}>
        @foreach($products as $product)
            @php
                $price = $productService->getProductPrice($product);
                $features = collect($product->features ?? [])->pluck('feature')->filter()->values()->all();
            @endphp

            <x-fp.plan-card
                :name="$product->name"
                :description="$product->description"
                :price="money($product->partner_price ?? $price->price, $price->currency->code)"
                :interval="__('one-off')"
                :features="$features"
                :partner="$product->partner_price !== null ? $product->partner_tenant_name : null"
                :href="route('buy.product', ['productSlug' => $product->slug])"
                :cta="__('Get :name', ['name' => $product->name])"
            >
                @if (!empty($extraDescription))
                    <p class="fp-plan-card-desc">{{ $extraDescription }}</p>
                @endif
            </x-fp.plan-card>
        @endforeach
    </div>
@endif
```

- [ ] **Step 5: Run to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=PricingPageTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/View/Components/Products/All.php resources/views/components/products/all.blade.php tests/Feature/Http/Controllers/PricingPageTest.php
git commit -m "feat(partner): filter the one-time products catalog to a referred customer's enabled offerings"
```

---

### Task 5: Reject checkout of a non-enabled item server-side

**Files:**
- Create: `app/Exceptions/PurchaseNotAllowedException.php`
- Modify: `app/Services/CheckoutService.php`
- Modify: `app/Livewire/Checkout/SubscriptionCheckoutForm.php`, `LocalSubscriptionCheckoutForm.php`, `ProductCheckoutForm.php`
- Create: `tests/Feature/Services/CheckoutServicePurchasabilityTest.php`

**Interfaces:**
- Consumes: `PartnerPricingResolver::purchasablePlans()`/`purchasableProducts()` (Task 2).
- Produces: `CheckoutService::initSubscriptionCheckout()`, `initLocalSubscriptionCheckout()`, `initProductCheckout()` throw `PurchaseNotAllowedException` when the resolved buyer is attributed and the target plan/product has no usable offering. All three consuming Livewire forms catch it and redirect back with a flash error.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/CheckoutServicePurchasabilityTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Dto\TotalsDto;
use App\Exceptions\PurchaseNotAllowedException;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CheckoutService;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class CheckoutServicePurchasabilityTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    public function test_an_attributed_buyer_cannot_check_out_a_not_configured_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $this->expectException(PurchaseNotAllowedException::class);

        app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);
    }

    public function test_an_attributed_buyer_can_check_out_a_configured_plan(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);

        $this->assertNotNull($subscription);
    }

    public function test_a_direct_buyer_can_still_check_out_any_active_plan(): void
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $this->actingAs($this->createUser());

        $subscription = app(CheckoutService::class)->initSubscriptionCheckout($plan->slug, null, 1, true);

        $this->assertNotNull($subscription);
    }

    public function test_an_attributed_buyer_cannot_check_out_a_not_configured_product(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $cartItem = new CartItemDto();
        $cartItem->productId = (string) $product->id;
        $cartItem->quantity = 1;

        $cartDto = new CartDto();
        $cartDto->items = [$cartItem];

        $totals = new TotalsDto();
        $totals->amountDue = 4900;
        $totals->currencyCode = 'USD';

        $this->expectException(PurchaseNotAllowedException::class);

        app(CheckoutService::class)->initProductCheckout($cartDto, null, $totals, true);
    }
}
```

`CartItemDto` and `TotalsDto` are plain property-bag classes with no constructor (verified: `app/Dto/CartItemDto.php` and `app/Dto/TotalsDto.php`) — always instantiate with `new` then set properties, never named-argument construction. `CartItemDto::$productId` is typed `?string` even though `OneTimeProductService::getOneTimeProductById()` takes `?int` — this file does not run under `declare(strict_types=1)`, and `snapshotForCart()` in `CheckoutService` already passes `$firstItem->productId` straight through with no cast, so PHP's weak-typing coercion handles it; match that existing pattern rather than adding a cast inside `CheckoutService` itself.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec laravel.test php artisan test --compact --filter=CheckoutServicePurchasabilityTest`
Expected: FAIL — `PurchaseNotAllowedException` class doesn't exist yet, and no check exists to throw it.

- [ ] **Step 3: Create the exception**

Create `app/Exceptions/PurchaseNotAllowedException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by CheckoutService when an attributed buyer's target plan or
 * product has no usable partner offering (spec §6, catalog restriction) —
 * the server-side twin of the display filter in PartnerPricingResolver::
 * purchasablePlans()/purchasableProducts(), so a crafted direct request
 * cannot buy what the storefront never showed.
 */
class PurchaseNotAllowedException extends Exception {}
```

- [ ] **Step 4: Enforce in `CheckoutService`**

In `app/Services/CheckoutService.php`, add `PartnerPricingResolver` to the constructor and a private helper, then call it at the top of all three `init*` methods:

```php
use App\Exceptions\PurchaseNotAllowedException;
use App\Services\CashPayments\PurchaseSnapshotService;

class CheckoutService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private OrderService $orderService,
        private TenantCreationService $tenantCreationService,
        private PlanService $planService,
        private OneTimeProductService $oneTimeProductService,
        private PurchaseSnapshotService $purchaseSnapshotService,
        private PartnerPricingResolver $partnerPricingResolver,
    ) {}

    public function initSubscriptionCheckout(string $planSlug, ?string $tenantUuid, int $quantity = 1, bool $shouldCreateNewTenant = false)
    {
        $plan = $this->planService->getActivePlanBySlug($planSlug);
        $this->assertPlanPurchasable($plan);
        $tenant = $this->resolveSubscriptionTenant($shouldCreateNewTenant, $tenantUuid, $plan);

        // ... rest of the method is unchanged
```

```php
    public function initLocalSubscriptionCheckout(string $planSlug, ?string $tenantUuid, int $quantity = 1, bool $shouldCreateNewTenant = false)
    {
        $plan = $this->planService->getActivePlanBySlug($planSlug);
        $this->assertPlanPurchasable($plan);
        $tenant = $this->resolveSubscriptionTenant($shouldCreateNewTenant, $tenantUuid, $plan);

        // ... rest of the method is unchanged
```

```php
    public function initProductCheckout(CartDto $cartDto, ?string $tenantUuid, TotalsDto $totalsDto, bool $shouldCreateNewTenant = false)
    {
        $user = auth()->user();

        $firstItem = $cartDto->items[0] ?? null;
        if ($firstItem !== null) {
            $this->assertProductPurchasable($this->oneTimeProductService->getOneTimeProductById($firstItem->productId));
        }

        $isLocalOrder = $totalsDto->amountDue === 0; // If amount due is zero, it's a local order (no payment provider needed)

        // ... rest of the method is unchanged
```

Add the two private helpers at the bottom of the class, right after `snapshotForCart()`:

```php
    /**
     * @throws PurchaseNotAllowedException
     */
    private function assertPlanPurchasable(Plan $plan): void
    {
        if ($this->partnerPricingResolver->purchasablePlans(collect([$plan]), auth()->user())->isEmpty()) {
            throw new PurchaseNotAllowedException("Plan [{$plan->slug}] is not available for this buyer.");
        }
    }

    /**
     * @throws PurchaseNotAllowedException
     */
    private function assertProductPurchasable(OneTimeProduct $product): void
    {
        if ($this->partnerPricingResolver->purchasableProducts(collect([$product]), auth()->user())->isEmpty()) {
            throw new PurchaseNotAllowedException("Product [{$product->slug}] is not available for this buyer.");
        }
    }
```

(Add `use App\Models\OneTimeProduct;` to the imports — `App\Models\Plan` is already imported.)

- [ ] **Step 5: Catch it in the three Livewire forms**

In `app/Livewire/Checkout/SubscriptionCheckoutForm.php::checkout()`, wrap the existing `initSubscriptionCheckout()` call (which already has a `try`/`catch (SubscriptionCreationNotAllowedException $e)` block) with an additional catch:

```php
        try {
            $subscription = $checkoutService->initSubscriptionCheckout(
                $planSlug,
                $subscriptionCheckoutDto->tenantUuid,
                $subscriptionCheckoutDto->quantity,
                $subscriptionCheckoutDto->shouldCreateNewTenant,
            );
        } catch (SubscriptionCreationNotAllowedException $e) {
            return redirect()->route('checkout.subscription.already-subscribed');
        } catch (PurchaseNotAllowedException $e) {
            return redirect()->back()->with('error', __('This plan is not available for your account.'));
        }
```

(Add `use App\Exceptions\PurchaseNotAllowedException;` to the imports.)

In `app/Livewire/Checkout/LocalSubscriptionCheckoutForm.php`, the same pattern around its existing `initLocalSubscriptionCheckout()` call:

```php
        try {
            $subscription = $checkoutService->initLocalSubscriptionCheckout(
                $planSlug,
                $subscriptionCheckoutDto->tenantUuid,
                $subscriptionCheckoutDto->quantity,
                $subscriptionCheckoutDto->shouldCreateNewTenant,
            );
        } catch (SubscriptionCreationNotAllowedException $e) {
            return redirect()->route('checkout.subscription.already-subscribed');
        } catch (PurchaseNotAllowedException $e) {
            return redirect()->back()->with('error', __('This plan is not available for your account.'));
        }
```

(Add `use App\Exceptions\PurchaseNotAllowedException;` to the imports.)

In `app/Livewire/Checkout/ProductCheckoutForm.php::checkout()`, the existing `initProductCheckout()` call has no surrounding `try`/`catch` at all — add one:

```php
        try {
            $order = $checkoutService->initProductCheckout(
                $cartDto,
                $cartDto->tenantUuid,
                $totals,
                $cartDto->shouldCreateNewTenant,
            );
        } catch (PurchaseNotAllowedException $e) {
            return redirect()->back()->with('error', __('This product is not available for your account.'));
        }
```

(Add `use App\Exceptions\PurchaseNotAllowedException;` to the imports.)

- [ ] **Step 6: Run to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter=CheckoutServicePurchasabilityTest`
Expected: PASS.

- [ ] **Step 7: Run the wider checkout slice for regressions**

Run: `docker compose exec laravel.test php artisan test --compact --filter="Checkout|CheckoutService|Subscription"`
Expected: PASS. If an existing checkout test now fails with `PurchaseNotAllowedException` for an attributed buyer, that fixture is purchasing a plan/product with no offering set up — give it one via `PartnerPlanOffering::factory()`/`PartnerProductOffering::factory()`, matching this task's own test fixtures, rather than weakening the new guard.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/PurchaseNotAllowedException.php app/Services/CheckoutService.php app/Livewire/Checkout/SubscriptionCheckoutForm.php app/Livewire/Checkout/LocalSubscriptionCheckoutForm.php app/Livewire/Checkout/ProductCheckoutForm.php tests/Feature/Services/CheckoutServicePurchasabilityTest.php
git commit -m "feat(partner): reject checkout of a plan or product the buyer's partner hasn't enabled"
```

---

### Task 6: Whole-branch verification

**Files:** none new. Fix-ups only where the checks below demand them.

- [ ] **Step 1: Formatting and static analysis**

```bash
vendor/bin/pint
vendor/bin/pint --test
```
Run from `backend/`. Expected: clean, no changes on the second run.

```bash
docker compose exec laravel.test vendor/bin/phpstan analyse
```
Run from the repo root. Expected: exactly the one pre-existing error (`AuditGroupDeltaService.php:112`); fix any other new error using the `optional()` pattern documented in Global Constraints.

- [ ] **Step 2: Full backend suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: all green.

- [ ] **Step 3: Commit any fix-ups**

Only if Step 1 or Step 2 required a change. Check `git status` first — stage only the files you actually touched in this step, never `-A`/`.`.

```bash
git add <files you actually changed>
git commit -m "chore(partner): pint/phpstan fix-ups after the catalog restriction work"
```

---
