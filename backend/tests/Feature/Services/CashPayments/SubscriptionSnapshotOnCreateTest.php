<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\Currency;
use App\Models\PartnerPlanOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class SubscriptionSnapshotOnCreateTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $partnerPlan = Plan::factory()->create(['product_id' => $partnerProduct->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $partnerPlan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function sellablePlan(array $metadata): Plan
    {
        $product = Product::factory()->create([
            'reseller_quota_keys' => array_keys($metadata),
            'metadata' => $metadata,
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'slug' => Str::random(12),
            'is_active' => true,
            'is_visible' => true,
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        return $plan;
    }

    /**
     * A partner price can only ever be charged in cash (spec §8.1), so any
     * test that expects PartnerPricingResolver to actually treat an offering
     * as usable must say so explicitly here — FeatureTest does not reset
     * state between test methods, so leaving this implicit would make later
     * tests silently depend on an earlier one's side effect.
     */
    private function activateOfflineProvider(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->update(['is_active' => true]);
    }

    public function test_a_direct_subscription_freezes_the_base_metadata(): void
    {
        $plan = $this->sellablePlan(['audit_deep_ai_credits' => 2]);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = app(SubscriptionService::class)->create(
            planSlug: $plan->slug,
            userId: $user->id,
            quantity: 1,
            tenant: $tenant,
        )->fresh();

        $this->assertNull($subscription->partner_tenant_id);
        $this->assertSame(4900, (int) $subscription->base_price_snapshot);
        $this->assertSame(['audit_deep_ai_credits' => 2], $subscription->quota_snapshot);
    }

    public function test_a_partner_attributed_subscription_freezes_the_partner_overrides(): void
    {
        $this->activateOfflineProvider();
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->sellablePlan(['audit_deep_ai_credits' => 2]);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $user->update(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => ['audit_deep_ai_credits' => 5],
            'is_enabled' => true,
        ]);

        $subscription = app(SubscriptionService::class)->create(
            planSlug: $plan->slug,
            userId: $user->id,
            quantity: 1,
            tenant: $tenant,
        )->fresh();

        $this->assertSame($partnerTenant->id, $subscription->partner_tenant_id);
        $this->assertSame(['audit_deep_ai_credits' => 5], $subscription->quota_snapshot);
    }
}
