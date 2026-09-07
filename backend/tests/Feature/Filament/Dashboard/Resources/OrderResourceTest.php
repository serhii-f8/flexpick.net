<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTest;

class OrderResourceTest extends FeatureTest
{
    public function test_list(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        ]);

        $this->actingAs($user);

        $response = $this->get(OrderResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))->assertSuccessful();

        $response->assertStatus(200);
    }

    public function test_list_fails_when_user_has_no_permission(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->actingAs($user);
        $this->expectException(HttpException::class);

        $this->get(OrderResource::getUrl('index', [], true, 'dashboard', tenant: $tenant));
    }

    /**
     * Since Task 12, partner_tenant_id is stamped on every order by an
     * attributed buyer, not only partner-priced ones. "Sold Through" must
     * not claim a plain gateway sale was sold through the partner -- that
     * requires is_local too (the same convention
     * PartnerOrderResource::ownsCashOrder() uses for a genuine partner
     * sale). Table column, list and view page all covered here.
     */
    public function test_sold_through_is_hidden_for_a_stamped_but_non_local_order(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_VIEW_ORDERS]);
        $this->actingAs($user);
        $partnerTenant = Tenant::factory()->create(['name' => 'Acme Reseller']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'is_local' => false,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $this->get(OrderResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertDontSee('Acme Reseller');

        $this->get(OrderResource::getUrl('view', ['record' => $order], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertDontSee('Acme Reseller');
    }

    public function test_sold_through_is_shown_for_a_local_partner_priced_order(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_VIEW_ORDERS]);
        $this->actingAs($user);
        $partnerTenant = Tenant::factory()->create(['name' => 'Acme Reseller']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'is_local' => true,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $this->get(OrderResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Acme Reseller');

        $this->get(OrderResource::getUrl('view', ['record' => $order], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Acme Reseller');
    }
}
