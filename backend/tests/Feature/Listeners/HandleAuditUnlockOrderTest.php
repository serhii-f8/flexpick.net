<?php

namespace Tests\Feature\Listeners;

use App\Events\Order\Ordered;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class HandleAuditUnlockOrderTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function unlockOrderFor(User $user): Order
    {
        $product = OneTimeProduct::firstOrCreate(
            ['slug' => config('audit.unlock_product_slug')],
            ['name' => 'Audit Report Unlock', 'description' => 'Unlock full audit report', 'features' => []],
        );
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 500,
            'price_per_unit_after_discount' => 500,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    public function test_unlock_order_unlocks_intended_report(): void
    {
        $user = User::factory()->create();
        $reportA = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $reportB = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        UserParameter::create(['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM, 'value' => $reportA->uuid]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $this->assertNotNull($reportA->refresh()->unlocked_at);
        $this->assertNull($reportB->refresh()->unlocked_at);
        $this->assertSame($reportA->unlock_order_id, Order::latest('id')->value('id'));
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM]);
    }

    public function test_without_intent_falls_back_to_latest_locked_report(): void
    {
        $user = User::factory()->create();
        AuditReport::factory()->locked()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $latest = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        Ordered::dispatch($this->unlockOrderFor($user));

        $this->assertNotNull($latest->refresh()->unlocked_at);
    }

    public function test_non_unlock_orders_are_ignored(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);
        $product = OneTimeProduct::factory()->create(['slug' => 'something-else']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id, 'quantity' => 1, 'currency_id' => $order->currency_id,
            'price_per_unit' => 100, 'price_per_unit_after_discount' => 100, 'discount_per_unit' => 0,
        ]);

        Ordered::dispatch($order);

        $this->assertNull($report->refresh()->unlocked_at);
    }
}
