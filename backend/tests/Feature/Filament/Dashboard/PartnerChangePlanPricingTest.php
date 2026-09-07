<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
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
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        ], [
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

        // Verify the partner offering was created
        $offering = PartnerPlanOffering::where('tenant_id', $partnerTenant->id)
            ->where('plan_id', $plan->id)
            ->first();
        $this->assertNotNull($offering, 'Partner offering should exist');

        // Verify the customer is attributed
        $this->assertEquals($partnerTenant->id, $customer->partner_tenant_id);

        // Verify partner pricing resolver finds the offering
        $resolver = app(PartnerPricingResolver::class);
        $resolvedPrice = $resolver->planPrice($customer, $plan);
        $this->assertEquals(7900, $resolvedPrice, 'Resolver should find partner price of 7900');

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($customerTenant);

        $html = $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $customerTenant))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString((string) money(7900, 'USD'), $html);
        $this->assertStringNotContainsString((string) money(4900, 'USD'), $html);
    }

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
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        ], [
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
        $customer = $this->createUser($customerTenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        ], [
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
}
