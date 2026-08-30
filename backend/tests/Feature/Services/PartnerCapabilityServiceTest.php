<?php

namespace Tests\Feature\Services;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerCapabilityService;
use Tests\Feature\FeatureTest;

class PartnerCapabilityServiceTest extends FeatureTest
{
    public function test_tenant_with_active_reseller_plan_is_an_active_partner(): void
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

        $this->assertTrue(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_a_non_reseller_plan_is_not_a_partner(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => []]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_no_active_subscription_is_not_a_partner(): void
    {
        $tenant = $this->createTenant();

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_an_expired_reseller_subscription_is_not_a_partner(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->subDay(),
        ]);

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }
}
