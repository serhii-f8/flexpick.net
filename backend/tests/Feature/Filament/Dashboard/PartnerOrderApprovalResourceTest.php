<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages\ListPartnerOrderApprovals;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\PartnerOrderApprovalResource;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerOrderApprovalResourceTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
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

    private function pendingOrderFor(Tenant $partnerTenant, string $status = OrderStatus::PENDING->value): Order
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => $status,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
        ]);
    }

    public function test_a_non_partner_tenant_cannot_access_the_queue(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertFalse(PartnerOrderApprovalResource::canAccess());
    }

    public function test_a_partner_tenant_member_without_the_permission_cannot_access_the_queue(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, []);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertFalse(PartnerOrderApprovalResource::canAccess());
    }

    public function test_a_permitted_partner_can_access_the_queue(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertTrue(PartnerOrderApprovalResource::canAccess());
    }

    public function test_the_queue_lists_only_this_partners_pending_orders(): void
    {
        $tenant = $this->activePartnerTenant();
        $otherTenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);

        $mine = $this->pendingOrderFor($tenant);
        $alreadyDone = $this->pendingOrderFor($tenant, OrderStatus::SUCCESS->value);
        $theirs = $this->pendingOrderFor($otherTenant);

        $gatewayCustomerTenant = $this->createTenant();
        $gatewayCustomer = $this->createUser($gatewayCustomerTenant);
        $gatewayOrder = Order::factory()->create([
            'user_id' => $gatewayCustomer->id,
            'tenant_id' => $gatewayCustomerTenant->id,
            'partner_tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => false,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $ids = PartnerOrderApprovalResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($alreadyDone->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($gatewayOrder->id, $ids);
    }

    public function test_the_approve_action_completes_the_order_and_logs_the_note(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($tenant);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerOrderApprovals::class)
            ->callTableAction('approve', $order, data: ['note' => 'Cash collected on site.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame('Cash collected on site.', OrderApproval::where('order_id', $order->id)->value('note'));
    }

    public function test_the_reject_action_rejects_the_order(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($tenant);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerOrderApprovals::class)
            ->callTableAction('reject', $order, data: ['note' => 'Customer never paid.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }
}
