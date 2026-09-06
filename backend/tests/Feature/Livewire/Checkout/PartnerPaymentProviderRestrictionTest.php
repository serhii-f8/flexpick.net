<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Livewire\Checkout\SubscriptionCheckoutForm;
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
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
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
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
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
        $filtered = SubscriptionCheckoutForm::restrictToPartnerProviders(
            $all,
            app(PartnerPricingResolver::class)->usablePlanOffering($user, $plan),
        );

        $this->assertSame($this->slugs($all), $this->slugs($filtered));
    }
}
