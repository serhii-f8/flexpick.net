<?php

namespace Tests\Feature\Models;

use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class SubscriptionSnapshotColumnsTest extends FeatureTest
{
    public function test_a_subscription_carries_a_partner_and_a_frozen_snapshot(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_deep_ai_credits' => 3],
        ])->fresh();

        $this->assertTrue($subscription->partnerTenant->is($partnerTenant));
        $this->assertSame(4900, (int) $subscription->base_price_snapshot);
        $this->assertSame(['audit_deep_ai_credits' => 3], $subscription->quota_snapshot);
    }

    public function test_a_subscription_lists_its_cash_orders(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'type' => OrderType::RENEWAL->value,
        ]);

        $this->assertCount(1, $subscription->fresh()->orders);
        $this->assertSame(OrderType::RENEWAL->value, $subscription->fresh()->orders->first()->type);
    }
}
