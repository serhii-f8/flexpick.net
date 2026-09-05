<?php

namespace Tests\Feature\Models;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class OrderApprovalTest extends FeatureTest
{
    public function test_it_records_who_decided_what(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $approval = OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::PARTNER->value,
            'actor_user_id' => $user->id,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => 'Cash received in person.',
            'decided_at' => now(),
        ])->fresh();

        $this->assertTrue($approval->order->is($order));
        $this->assertTrue($approval->actorUser->is($user));
        $this->assertSame(OrderApprovalDecision::APPROVED->value, $approval->decision);
        $this->assertTrue($order->fresh()->approval->is($approval));
    }

    public function test_an_order_can_only_be_decided_once(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        OrderApproval::factory()->create(['order_id' => $order->id]);

        $this->expectException(QueryException::class);

        OrderApproval::factory()->create(['order_id' => $order->id]);
    }

    public function test_a_system_decision_has_no_actor_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $approval = OrderApproval::factory()->create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::SYSTEM->value,
            'actor_user_id' => null,
            'decision' => OrderApprovalDecision::REJECTED->value,
        ])->fresh();

        $this->assertNull($approval->actor_user_id);
        $this->assertNull($approval->actorUser);
    }
}
