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
