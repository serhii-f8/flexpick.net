<?php

namespace Tests\Feature\Models;

use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class OrderSnapshotColumnsTest extends FeatureTest
{
    public function test_an_order_carries_a_partner_and_a_frozen_snapshot(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 25],
            'subscription_id' => $subscription->id,
            'type' => OrderType::RENEWAL->value,
        ]);

        $fresh = $order->fresh();

        $this->assertTrue($fresh->partnerTenant->is($partnerTenant));
        $this->assertTrue($fresh->subscription->is($subscription));
        $this->assertSame(4900, (int) $fresh->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 25], $fresh->quota_snapshot);
        $this->assertSame(OrderType::RENEWAL->value, $fresh->type);
    }

    public function test_an_ordinary_order_defaults_to_a_purchase_with_no_partner(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id])->fresh();

        $this->assertSame(OrderType::PURCHASE->value, $order->type);
        $this->assertNull($order->partner_tenant_id);
        $this->assertNull($order->subscription_id);
        $this->assertNull($order->quota_snapshot);
    }
}
