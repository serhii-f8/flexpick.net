<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\Pages\ListPartnerPlanCatalog;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\PartnerPlanCatalogResource;
use App\Models\Currency;
use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Filament\Facades\Filament;
use Livewire\Livewire;
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

    public function test_configure_action_saves_a_valid_offering(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = Product::factory()->create([
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
            'metadata' => ['audit_diagnostic_credits' => 10],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_visible' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerPlanCatalog::class)
            ->callTableAction('configure', $plan, data: [
                'price' => 4900,
                'quota_overrides' => ['audit_diagnostic_credits' => '15'],
                'is_enabled' => true,
            ])
            ->assertHasNoTableActionErrors();

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first();
        $this->assertNotNull($offering);
        $this->assertSame(4900, $offering->price);
        $this->assertSame(['audit_diagnostic_credits' => 15], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_configure_action_accepts_a_blank_quota_field(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = Product::factory()->create([
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
            'metadata' => ['audit_diagnostic_credits' => 10],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_visible' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerPlanCatalog::class)
            ->callTableAction('configure', $plan, data: [
                'price' => 4900,
                'quota_overrides' => ['audit_diagnostic_credits' => null],
                'is_enabled' => true,
            ])
            ->assertHasNoTableActionErrors();

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first();
        $this->assertNotNull($offering);
        $this->assertSame([], $offering->quota_overrides);
    }

    public function test_configure_action_does_not_leak_an_offering_across_tenants(): void
    {
        $tenantA = $this->activePartnerTenant();
        $tenantB = $this->activePartnerTenant();
        $userA = $this->createUser($tenantA, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = Product::factory()->create([
            'reseller_quota_keys' => [],
            'metadata' => [],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_visible' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenantA);

        Livewire::actingAs($userA)
            ->test(ListPartnerPlanCatalog::class)
            ->callTableAction('configure', $plan, data: [
                'price' => 4900,
                'quota_overrides' => [],
                'is_enabled' => true,
            ]);

        $this->assertNotNull(PartnerPlanOffering::where('tenant_id', $tenantA->id)->where('plan_id', $plan->id)->first());
        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenantB->id)->where('plan_id', $plan->id)->first());
    }
}
