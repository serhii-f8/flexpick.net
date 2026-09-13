<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_authenticated_user_can_view_pricing(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
        $response->assertDontSee(__('Buying a plan here starts a brand-new workspace.'));
    }

    /**
     * The bug this guards against: a customer whose active subscription is
     * cash/partner-managed has no self-service way to change it, so buying
     * a plan here would otherwise silently spin up a second workspace
     * (DashboardPanelProvider::upgradeUrl()'s counterpart for the one path
     * it can't redirect away from).
     */
    public function test_a_customer_with_a_non_self_service_subscription_sees_the_new_workspace_warning(): void
    {
        $this->visiblePlan([], 'Any Visible Plan');
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['is_active' => true])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'ends_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Buying a plan here starts a brand-new workspace.'));
    }

    public function test_a_customer_with_a_self_service_subscription_does_not_see_the_warning(): void
    {
        $this->visiblePlan([], 'Any Visible Plan');
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['is_active' => true])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'ends_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertDontSee(__('Buying a plan here starts a brand-new workspace.'));
    }

    public function test_a_guest_can_view_pricing_and_is_offered_sign_up(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
        $response->assertSee(__('Sign up'));
        $response->assertSee(route('register'), false);
        $response->assertSee(route('login'), false);
    }

    public function test_a_signed_in_user_is_not_offered_sign_up(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertDontSee(__('Sign up'));
    }

    public function test_single_audits_get_their_own_heading_ahead_of_the_subscription_panel(): void
    {
        $user = $this->createUser();

        $html = $this->actingAs($user)->get(route('pricing'))->assertOk()->getContent();

        $this->assertStringContainsString(__('Buy a single audit'), $html);
        $this->assertLessThan(
            strpos($html, __('Subscribe and keep auditing')),
            strpos($html, __('Buy a single audit')),
        );
    }

    public function test_a_partner_customer_sees_the_partner_named_on_the_plan_card(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

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
            'price' => 4900,
        ]);
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
            'partner_attribution_source' => 'registration',
        ]);
        app(PartnerPricingResolver::class)->flush();

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSee(__('Sold through :partner', ['partner' => $partnerTenant->name]));
    }

    /**
     * plans.meter_id is nullable at the DB level and only the admin form enforces
     * "usage-based plans must have a meter". The pricing card, though, branches on
     * the PlanPrice type rather than the Plan type, so a plan carrying a tiered
     * usage-based *price* with no meter reaches the meter name and used to fatal
     * the whole public page. Guarded at plans/one.blade.php:38,45,46.
     */
    public function test_the_pricing_page_survives_a_tiered_usage_based_plan_with_no_meter(): void
    {
        $user = $this->createUser();
        $product = Product::factory()->create(['name' => 'Meterless '.uniqid()]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'name' => 'Meterless Tiered '.uniqid(),
            'type' => PlanType::USAGE_BASED->value,
            'meter_id' => null,
            'is_active' => true,
            'is_visible' => true,
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'type' => PlanPriceType::USAGE_BASED_TIERED_VOLUME->value,
            'tiers' => [[
                PlanPriceTierConstants::UNTIL_UNIT => 7331,
                PlanPriceTierConstants::PER_UNIT => 25,
                PlanPriceTierConstants::FLAT_FEE => 0,
            ]],
        ]);

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        // The card renders the product name, and the tier line proves we actually
        // reached the meter-name branch rather than merely not crashing.
        $response->assertSee($product->name);
        $response->assertSee('0–7331');
    }

    public function test_both_catalog_tabs_render_with_one_time_products_first(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSeeInOrder([__('One-time products'), __('Subscriptions')]);
    }

    public function test_the_one_time_products_panel_is_the_one_visible_on_load(): void
    {
        $user = $this->createUser();

        $html = $this->actingAs($user)->get(route('pricing'))->assertOk()->getContent();

        $this->assertStringContainsString("catalog: 'products'", $html);
        $this->assertMatchesRegularExpression('/id="catalog-plans"[^>]*x-cloak/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="catalog-products"[^>]*x-cloak/', $html);
    }

    public function test_the_free_plan_panel_stays_outside_both_catalog_tabs(): void
    {
        Product::factory()->create(['is_default' => true, 'name' => 'Free Forever '.uniqid()]);
        $user = $this->createUser();

        $html = $this->actingAs($user)->get(route('pricing'))->assertOk()->getContent();

        $tabsEnd = strpos($html, '<!-- /fp-pricing-tabs -->');
        $this->assertNotFalse($tabsEnd, 'The catalog tab container marker is missing.');
        $this->assertGreaterThan($tabsEnd, strpos($html, __('Start for free')));
    }

    private function visiblePlan(array $metadata, string $name): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['name' => $name, 'metadata' => $metadata])->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 1000]);

        return $plan;
    }

    public function test_packages_are_grouped_under_their_tier_headings_in_tier_order(): void
    {
        $this->visiblePlan(['audit_tier_group' => 'expert'], 'Zed Expert Pack');
        $this->visiblePlan(['audit_tier_group' => 'diagnostic'], 'Alpha Diagnostic Pack');
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSeeInOrder([
            config('pricing.package_tiers.diagnostic.headline'),
            'Alpha Diagnostic Pack',
            config('pricing.package_tiers.expert.headline'),
            'Zed Expert Pack',
        ]);
    }

    public function test_plans_without_a_tier_render_in_a_trailing_unlabelled_grid(): void
    {
        $this->visiblePlan([], 'Loose Legacy Plan');
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSee('Loose Legacy Plan');
    }

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

    /**
     * Regression for Finding 5 of the final whole-branch review: Task 3's
     * empty-state `@if ($plans->isEmpty())` wrapper in
     * resources/views/components/plans/all.blade.php originally spanned the
     * entire file, including the pre-existing `@if (isset($defaultProduct))`
     * "Start for free" CTA at the end -- so a customer with zero purchasable
     * plans but a configured default free product lost that CTA entirely.
     * This can happen to ANY customer, attributed or not; reusing the
     * attributed-with-nothing-enabled setup here is just the most reliable
     * way to force $plans to empty regardless of what else exists in the
     * shared test database.
     */
    public function test_a_customer_with_zero_purchasable_plans_still_sees_the_default_product_cta(): void
    {
        $partnerTenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $partnerProduct->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $unconfiguredProduct = Product::factory()->create(['name' => 'Unconfigured Product For Default Test']);
        $unconfiguredPlan = Plan::factory()->create([
            'product_id' => $unconfiguredProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $unconfiguredPlan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        Product::factory()->create(['is_default' => true]);

        $customer = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get(route('pricing'));

        $response->assertSee(__('Nothing available yet — check back soon.'));
        $response->assertSee(__('Start for free'));
        $response->assertSee(__('Start now'));
    }

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
}
