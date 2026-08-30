<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\PartnerPlanCatalogResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class PartnerPlanCatalogResourceTest extends FeatureTest
{
    private function activePartnerTenant()
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

    public function test_it_denies_access_to_a_non_partner_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertFalse(PartnerPlanCatalogResource::canAccess());
    }

    public function test_it_allows_access_to_an_active_partner_tenant_with_permission(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertTrue(PartnerPlanCatalogResource::canAccess());
    }

    public function test_it_denies_access_without_the_permission_even_for_an_active_partner(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, []);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertFalse(PartnerPlanCatalogResource::canAccess());
    }

    public function test_only_visible_plans_are_listed(): void
    {
        Plan::factory()->create(['is_visible' => true]);
        Plan::factory()->create(['is_visible' => false]);

        $query = PartnerPlanCatalogResource::getEloquentQuery();

        $this->assertTrue($query->get()->every(fn (Plan $plan) => $plan->is_visible));
    }
}
