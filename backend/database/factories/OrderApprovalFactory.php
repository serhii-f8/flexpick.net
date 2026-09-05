<?php

namespace Database\Factories;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderApproval>
 */
class OrderApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'actor_type' => OrderApprovalActor::ADMIN->value,
            'actor_user_id' => null,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => null,
            'decided_at' => now(),
        ];
    }
}
