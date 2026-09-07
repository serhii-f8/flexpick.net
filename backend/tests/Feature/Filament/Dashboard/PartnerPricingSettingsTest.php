<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\PartnerPricingSettings;
use App\Livewire\Filament\Dashboard\PartnerPlanPricingTable;
use App\Livewire\Filament\Dashboard\PartnerProductPricingTable;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTest;

class PartnerPricingSettingsTest extends FeatureTest
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

    private function actAsPartner(Tenant $tenant, array $permissions = [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]): User
    {
        $user = $this->createUser($tenant, $permissions);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $user;
    }

    private function visiblePlan(int $basePrice, array $metadata = []): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['metadata' => $metadata])->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => $basePrice]);

        return $plan;
    }

    private function visibleProduct(int $basePrice): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        $product->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => $basePrice]);

        return $product;
    }

    public function test_access_requires_an_active_partner_and_the_catalog_permission(): void
    {
        $plain = $this->createTenant();
        $this->actAsPartner($plain);
        $this->assertFalse(PartnerPricingSettings::canAccess());

        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner, []);
        $this->assertFalse(PartnerPricingSettings::canAccess());

        $this->actAsPartner($partner);
        $this->assertTrue(PartnerPricingSettings::canAccess());
    }

    public function test_the_page_loads_and_names_both_tables(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);

        $this->get(PartnerPricingSettings::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Subscription packages'))
            ->assertSee(__('One-time reports'));
    }

    public function test_the_plan_table_shows_platform_price_your_price_and_margin(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275);
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'price' => 47500, 'quota_overrides' => [], 'is_enabled' => true]);
        Plan::factory()->create(['is_visible' => false, 'product_id' => Product::factory()->create()->id]);

        Livewire::test(PartnerPlanPricingTable::class)
            ->assertCanSeeTableRecords([$plan])
            ->assertSee((string) money(23275, 'USD'))
            ->assertSee((string) money(47500, 'USD'))
            ->assertSee((string) money(24225, 'USD'))
            ->assertSee(__('Enabled'));
    }

    public function test_set_price_saves_an_offering_in_cents(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275, ['partner_suggested_price' => 47500]);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 500.5, 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(50050, $offering->price);
        $this->assertSame([], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_a_price_below_the_platform_price_is_refused(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 100, 'is_enabled' => true])
            ->assertNotified(__('Could not save offering'));

        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first());
    }

    public function test_the_product_table_saves_a_product_offering(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $product = $this->visibleProduct(4900);

        Livewire::test(PartnerProductPricingTable::class)
            ->assertCanSeeTableRecords([$product])
            ->callTableAction('set-price', $product, data: ['price' => 100, 'is_enabled' => false])
            ->assertHasNoTableActionErrors();

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->firstOrFail();
        $this->assertSame(10000, $offering->price);
        $this->assertFalse($offering->is_enabled);
    }

    /**
     * canAccess() is only checked once, by the page, at load time. Without a
     * mount()-time re-check, a table rendered while access was still valid
     * would happily go on serving set-price actions after the partner plan
     * lapses -- exactly the "PartnerPlan can lapse between rendering the
     * queue and clicking Approve" window PartnerOrderResource is guarded
     * against, but Pricing Settings previously was not.
     */
    public function test_the_plan_table_refuses_to_mount_once_the_partner_plan_is_no_longer_active(): void
    {
        $plain = $this->createTenant();
        $this->actAsPartner($plain);

        $this->expectException(HttpException::class);

        Livewire::test(PartnerPlanPricingTable::class);
    }

    public function test_the_product_table_refuses_to_mount_once_the_partner_plan_is_no_longer_active(): void
    {
        $plain = $this->createTenant();
        $this->actAsPartner($plain);

        $this->expectException(HttpException::class);

        Livewire::test(PartnerProductPricingTable::class);
    }

    public function test_set_price_is_refused_when_the_partner_plan_lapses_after_the_table_renders(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275);

        $component = Livewire::test(PartnerPlanPricingTable::class);

        // The plan lapses in the window between rendering the table and the
        // partner clicking "Set price".
        Subscription::where('tenant_id', $tenant->id)->update(['ends_at' => now()->subDay()]);

        $component->callTableAction('set-price', $plan, data: ['price' => 500, 'is_enabled' => true])
            ->assertNotified(__('Could not save offering'));

        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first());
    }

    public function test_the_product_tables_set_price_is_also_refused_when_the_partner_plan_lapses_after_render(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $product = $this->visibleProduct(4900);

        $component = Livewire::test(PartnerProductPricingTable::class);

        Subscription::where('tenant_id', $tenant->id)->update(['ends_at' => now()->subDay()]);

        $component->callTableAction('set-price', $product, data: ['price' => 100, 'is_enabled' => true])
            ->assertNotified(__('Could not save offering'));

        $this->assertNull(PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->first());
    }

    public function test_an_offering_never_leaks_across_tenants(): void
    {
        $tenantA = $this->activePartnerTenant();
        $tenantB = $this->activePartnerTenant();
        $plan = $this->visiblePlan(23275);
        $this->actAsPartner($tenantA);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 300, 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(PartnerPlanOffering::where('tenant_id', $tenantA->id)->where('plan_id', $plan->id)->first());
        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenantB->id)->where('plan_id', $plan->id)->first());
    }
}
