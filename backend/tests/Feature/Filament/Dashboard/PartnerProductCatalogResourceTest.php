<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerProductCatalog\Pages\ListPartnerProductCatalog;
use App\Filament\Dashboard\Resources\PartnerProductCatalog\PartnerProductCatalogResource;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerProductCatalogResourceTest extends FeatureTest
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

    public function test_it_allows_access_to_an_active_partner_tenant_with_permission(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertTrue(PartnerProductCatalogResource::canAccess());
    }

    public function test_it_denies_access_to_a_non_partner_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertFalse(PartnerProductCatalogResource::canAccess());
    }

    public function test_it_denies_access_without_the_permission_even_for_an_active_partner(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, []);
        $this->actingAs($user);

        Filament::setTenant($tenant);

        $this->assertFalse(PartnerProductCatalogResource::canAccess());
    }

    public function test_only_visible_products_are_listed(): void
    {
        OneTimeProduct::factory()->create(['is_visible' => true]);
        OneTimeProduct::factory()->create(['is_visible' => false]);

        $query = PartnerProductCatalogResource::getEloquentQuery();

        $this->assertTrue($query->get()->every(fn (OneTimeProduct $p) => $p->is_visible));
    }

    public function test_configure_action_saves_a_valid_offering(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = OneTimeProduct::factory()->create([
            'is_visible' => true,
            'reseller_quota_keys' => ['bonus_credits'],
            'metadata' => ['bonus_credits' => 10],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 1500,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerProductCatalog::class)
            ->callTableAction('configure', $product, data: [
                'price' => 1500,
                'quota_overrides' => ['bonus_credits' => '15'],
                'is_enabled' => true,
            ])
            ->assertHasNoTableActionErrors();

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->first();
        $this->assertNotNull($offering);
        $this->assertSame(1500, $offering->price);
        $this->assertSame(['bonus_credits' => 15], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_configure_action_accepts_a_blank_quota_field(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = OneTimeProduct::factory()->create([
            'is_visible' => true,
            'reseller_quota_keys' => ['bonus_credits'],
            'metadata' => ['bonus_credits' => 10],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 1500,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerProductCatalog::class)
            ->callTableAction('configure', $product, data: [
                'price' => 1500,
                'quota_overrides' => ['bonus_credits' => null],
                'is_enabled' => true,
            ])
            ->assertHasNoTableActionErrors();

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->first();
        $this->assertNotNull($offering);
        $this->assertSame([], $offering->quota_overrides);
    }

    public function test_configure_action_does_not_leak_an_offering_across_tenants(): void
    {
        $tenantA = $this->activePartnerTenant();
        $tenantB = $this->activePartnerTenant();
        $userA = $this->createUser($tenantA, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $product = OneTimeProduct::factory()->create([
            'is_visible' => true,
            'reseller_quota_keys' => [],
            'metadata' => [],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 1500,
        ]);

        $this->actingAs($userA);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenantA);

        Livewire::actingAs($userA)
            ->test(ListPartnerProductCatalog::class)
            ->callTableAction('configure', $product, data: [
                'price' => 1500,
                'quota_overrides' => [],
                'is_enabled' => true,
            ]);

        $this->assertNotNull(PartnerProductOffering::where('tenant_id', $tenantA->id)->where('one_time_product_id', $product->id)->first());
        $this->assertNull(PartnerProductOffering::where('tenant_id', $tenantB->id)->where('one_time_product_id', $product->id)->first());
    }
}
