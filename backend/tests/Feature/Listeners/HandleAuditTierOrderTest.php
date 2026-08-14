<?php

namespace Tests\Feature\Listeners;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    public function test_a_purchased_run_is_prepaid_and_not_metered(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai');
        (new HandleAuditTierOrder)->handle(new Ordered($order));

        $run = AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue($run->prepaid);
        $this->assertSame(AuditFunding::PURCHASE, $run->funding);
        $this->assertSame(0, app(AuditEntitlementService::class)
            ->runsUsedThisMonth($user, AuditTier::DEEP_AI));
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_intent_run_is_used_instead_of_cloning_a_diagnostic(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();
        $intended = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/intended',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        UserParameter::create([
            'user_id' => $user->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => $intended->uuid,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai');
        (new HandleAuditTierOrder)->handle(new Ordered($order));

        $intended->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $intended->status);
        $this->assertTrue($intended->prepaid);
        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->count());
        $this->assertNull(UserParameter::where('name', HandleAuditTierOrder::INTENT_PARAM)->first());
    }

    private function orderFor(User $user, string $slug): Order
    {
        $product = OneTimeProduct::where('slug', $slug)->firstOrFail();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 19900,
            'price_per_unit_after_discount' => 19900,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }
}
