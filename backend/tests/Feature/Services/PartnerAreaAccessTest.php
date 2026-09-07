<?php

namespace Tests\Feature\Services;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerCapabilityService;
use Tests\Feature\FeatureTest;

class PartnerAreaAccessTest extends FeatureTest
{
    public function test_a_member_of_an_active_partner_tenant_can_access_the_partner_area(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $user = $this->createUser($tenant);

        $this->assertTrue(app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user));
    }

    public function test_a_plain_tenant_or_a_missing_tenant_or_user_cannot(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $service = app(PartnerCapabilityService::class);

        $this->assertFalse($service->userCanAccessPartnerArea($tenant, $user));
        $this->assertFalse($service->userCanAccessPartnerArea(null, $user));
        $this->assertFalse($service->userCanAccessPartnerArea($tenant, null));
    }
}
