<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
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
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->withExceptionHandling();
        $response = $this->get(route('pricing'));

        $response->assertRedirect(route('login'));
    }

    public function test_single_audits_get_their_own_heading_below_the_plans(): void
    {
        $user = $this->createUser();

        $html = $this->actingAs($user)->get(route('pricing'))->assertOk()->getContent();

        $this->assertStringContainsString(__('Or buy a single audit'), $html);
        $this->assertLessThan(
            strpos($html, __('Or buy a single audit')),
            strpos($html, __('Plans & Pricing')),
        );
    }

    public function test_a_partner_customer_sees_one_banner_naming_the_partner(): void
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

        $response->assertSee(__('Prices on this page are set by :partner.', ['partner' => $partnerTenant->name]));
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
