<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CurrencyService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class SubscriptionControllerTest extends FeatureTest
{
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

    public function test_change_plan(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS]);
        $this->actingAs($user);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => SubscriptionStatus::ACTIVE,
            'payment_provider_id' => PaymentProvider::where('slug', 'stripe')->first()->id,
        ]);

        $newPlan = Plan::factory()->create([
            'is_active' => true,
            'slug' => 'new-plan',
        ]);

        $planPrice = PlanPrice::factory()->create([
            'plan_id' => $newPlan->id,
            'price' => 100,
            'currency_id' => $subscription->currency_id,
        ]);

        $response = $this->get(route('subscription.change-plan', [
            'planSlug' => $newPlan->slug,
            'subscriptionUuid' => $subscription->uuid,
            'tenantUuid' => $tenant->uuid,
        ]));

        $response->assertStatus(200);
        $response->assertSee(__('Switch to :plan', ['plan' => $newPlan->product->name]));
        $response->assertSee(__('Confirm switch to :plan', ['plan' => $newPlan->product->name]));
        $response->assertDontSee('apprenticeship');
        $response->assertDontSee(__('Update Subscription'));
    }

    /**
     * Regression for Finding 2 of the final whole-branch review: this
     * controller's self-service change-plan flow is not partner-aware
     * (CalculationService::calculateNewPlanTotals() always computes the base
     * price), so an attributed buyer must be rejected from the entire flow
     * outright — even when the target plan has a fully configured, enabled
     * partner offering — rather than being let through to a confirmation
     * page that would show the wrong total.
     */
    public function test_change_plan_is_rejected_for_an_attributed_customer_even_with_a_configured_offering(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $partnerTenant = $this->activePartnerTenant();

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => SubscriptionStatus::ACTIVE,
            'payment_provider_id' => PaymentProvider::where('slug', 'stripe')->first()->id,
        ]);

        $newProduct = Product::factory()->create();
        $newPlan = Plan::factory()->create([
            'product_id' => $newProduct->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
            'slug' => 'new-plan-with-offering',
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $newPlan->id,
            'price' => 4900,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
        ]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $newPlan->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $response = $this->get(route('subscription.change-plan', [
            'planSlug' => $newPlan->slug,
            'subscriptionUuid' => $subscription->uuid,
            'tenantUuid' => $tenant->uuid,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('error', __('Plan changes for partner customers are handled by your partner directly.'));
    }

    public function test_change_plan_is_unaffected_for_a_direct_customer(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS]);
        $this->actingAs($user);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => SubscriptionStatus::ACTIVE,
            'payment_provider_id' => PaymentProvider::where('slug', 'stripe')->first()->id,
        ]);

        $newPlan = Plan::factory()->create([
            'is_active' => true,
            'slug' => 'new-plan-direct-customer',
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $newPlan->id,
            'price' => 100,
            'currency_id' => $subscription->currency_id,
        ]);

        $response = $this->get(route('subscription.change-plan', [
            'planSlug' => $newPlan->slug,
            'subscriptionUuid' => $subscription->uuid,
            'tenantUuid' => $tenant->uuid,
        ]));

        $response->assertStatus(200);
        $response->assertSessionMissing('error');
    }
}
