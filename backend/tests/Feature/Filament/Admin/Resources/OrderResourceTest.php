<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Tenant;
use Tests\Feature\FeatureTest;

class OrderResourceTest extends FeatureTest
{
    public function test_list(): void
    {
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $response = $this->get(OrderResource::getUrl('index', [], true, 'admin'))->assertSuccessful();

        $response->assertStatus(200);
    }

    /**
     * Since Task 12, partner_tenant_id is stamped on every order by an
     * attributed buyer, not only partner-priced ones. The "Partner Sale"
     * section must not claim a plain gateway sale was priced/sold through
     * the partner -- that requires is_local too, matching
     * PartnerOrderResource::ownsCashOrder()'s convention. The separate
     * "Approval & Attribution History" section is untouched by this and
     * must still show for a stamped, non-local order.
     */
    public function test_partner_sale_section_is_hidden_for_a_stamped_but_non_local_order(): void
    {
        $user = $this->createAdminUser();
        $this->actingAs($user);
        $tenant = $this->createTenant();
        $buyer = $this->createUser($tenant, [], ['partner_tenant_id' => Tenant::factory()->create()->id, 'partner_attributed_at' => now()]);
        $partnerTenant = Tenant::factory()->create(['name' => 'Acme Reseller']);

        $order = Order::factory()->create([
            'user_id' => $buyer->id,
            'tenant_id' => $tenant->id,
            'is_local' => false,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $this->get(OrderResource::getUrl('view', ['record' => $order], true, 'admin'))
            ->assertSuccessful()
            ->assertDontSee(__('Partner Sale'))
            ->assertSee(__('Approval & Attribution History'));
    }

    public function test_partner_sale_section_is_shown_for_a_local_partner_priced_order(): void
    {
        $user = $this->createAdminUser();
        $this->actingAs($user);
        $tenant = $this->createTenant();
        $buyer = $this->createUser($tenant);
        $partnerTenant = Tenant::factory()->create(['name' => 'Acme Reseller']);

        $order = Order::factory()->create([
            'user_id' => $buyer->id,
            'tenant_id' => $tenant->id,
            'is_local' => true,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $this->get(OrderResource::getUrl('view', ['record' => $order], true, 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Partner Sale'));
    }
}
