<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class SubscriptionCheckoutControllerTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Checkout only opens to referred buyers, and a referred buyer can
        // only subscribe to what their partner resells through Offline.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
    }

    private function offeredBy(Plan $plan, Tenant $partnerTenant): Plan
    {
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 9900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        return $plan;
    }

    public function test_checkout_loads()
    {
        $planSlug = 'plan-slug-'.rand(1, 1000000);

        $partner = $this->createActivePartnerTenant();
        $plan = Plan::factory()->create([
            'slug' => $planSlug,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
        ]);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->offeredBy($plan, $partner);

        $response = $this->asReferredGuest($partner)->followingRedirects()->get(route('checkout.subscription', [
            'planSlug' => $plan->slug,
        ]));

        $response->assertStatus(200);

        $response->assertSee('Complete Subscription');
    }

    public function test_checkout_loads_for_plan_with_trial()
    {
        $planSlug = 'plan-slug-'.rand(1, 1000000);

        $partner = $this->createActivePartnerTenant();
        $plan = Plan::factory()->create([
            'slug' => $planSlug,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'has_trial' => true,
            'trial_interval_count' => 7,
            'trial_interval_id' => Interval::where('slug', 'day')->first()->id,
        ]);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->offeredBy($plan, $partner);

        $response = $this->asReferredGuest($partner)->followingRedirects()->get(route('checkout.subscription', [
            'planSlug' => $plan->slug,
        ]));

        $response->assertStatus(200);

        $response->assertSee('Complete Subscription');
    }

    public function test_checkout_loads_for_plan_with_trial_without_payment_details_enabled()
    {
        config(['app.trial_without_payment.enabled' => true]);

        $planSlug = 'plan-slug-'.rand(1, 1000000);

        $partner = $this->createActivePartnerTenant();
        $plan = Plan::factory()->create([
            'slug' => $planSlug,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'has_trial' => true,
            'trial_interval_count' => 7,
            'trial_interval_id' => Interval::where('slug', 'day')->first()->id,
        ]);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->offeredBy($plan, $partner);

        $response = $this->asReferredGuest($partner)->followingRedirects()->get(route('checkout.subscription', [
            'planSlug' => $plan->slug,
        ]));

        $response->assertStatus(200);

        $response->assertSee('Complete Subscription');
    }
}
