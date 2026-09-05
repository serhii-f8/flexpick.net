<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CashPayments\OrderApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Feature\FeatureTest;

class PartnerOrderOwnershipTest extends FeatureTest
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

    private function pendingOrderFor(?Tenant $partnerTenant): Order
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant?->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
        ]);
    }

    public function test_a_permitted_partner_user_can_approve_their_own_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->assertTrue(app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant));
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_a_partner_cannot_touch_another_partners_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $otherPartnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($otherPartnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_direct_order_with_no_partner_is_not_partner_approvable(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor(null);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_lapsed_partner_plan_blocks_the_action(): void
    {
        $partnerTenant = $this->createTenant(); // never subscribed to a partner plan
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->rejectAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_tenant_member_without_the_permission_is_blocked(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, []);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }
}
