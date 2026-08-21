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
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    public function test_a_completed_tier_order_runs_the_repository_at_that_tier(): void
    {
        Queue::fake();

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $this->completeOrderFor($user, 'audit-automated');

        $upgraded = AuditRequest::where('user_id', $user->id)
            ->where('tier', AuditTier::AUTOMATED->value)
            ->first();

        $this->assertNotNull($upgraded, 'A tier purchase must produce a run at the purchased tier.');
        $this->assertSame('https://github.com/acme/app', $upgraded->repo_url);

        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_original_diagnostic_run_is_left_intact(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $diagnostic = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/acme/app',
        ]);

        $this->completeOrderFor($user, 'audit-automated');

        $this->assertSame(AuditTier::DIAGNOSTIC, $diagnostic->fresh()->tier);
    }

    public function test_an_unrelated_product_order_is_ignored(): void
    {
        Queue::fake();

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->completeOrderFor($user, 'some-other-product');

        // Not assertNothingPushed(): the Ordered event also has queued
        // listeners of its own (referral processing, order notification)
        // that push regardless of which product was purchased.
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    public function test_the_purchased_run_is_not_charged_against_the_free_quota(): void
    {
        Queue::fake();

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->completeOrderFor($user, 'audit-automated');

        $upgraded = AuditRequest::where('user_id', $user->id)
            ->where('tier', AuditTier::AUTOMATED->value)
            ->firstOrFail();

        $this->assertFalse((bool) $upgraded->free_run);
    }

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
        // A diagnostic must exist so a wrongful clone via the fallback path
        // is actually possible — otherwise the "still only one deep_ai row"
        // assertion below would pass even if the intent guard were removed.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);
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
        $this->assertNull(UserParameter::where('user_id', $user->id)->where('name', HandleAuditTierOrder::INTENT_PARAM)->first());
    }

    /**
     * The stored intent uuid can miss even though the customer really did
     * start a dashboard checkout for this tier -- an overlapping checkout
     * overwrote the single per-user intent row, or the purge job removed the
     * stale awaiting_payment row it pointed at. Either way, the buyer's most
     * recent awaiting_payment request at the ordered tier is still the
     * request they meant to pay for, and it must be found and run rather
     * than silently falling through.
     */
    public function test_a_stale_intent_uuid_falls_back_to_the_latest_awaiting_payment_request_at_that_tier(): void
    {
        Queue::fake();
        $this->seed(AuditMonetizationSeeder::class);

        $user = $this->createUser();

        // The intent parameter points at a uuid that no longer resolves to
        // any request -- e.g. the row it named was purged or overwritten.
        UserParameter::create([
            'user_id' => $user->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => (string) Str::uuid(),
        ]);

        $target = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/fallback-target',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai');
        (new HandleAuditTierOrder)->handle(new Ordered($order));

        $target->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $target->status);
        $this->assertTrue($target->prepaid);
        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('user_id', $user->id)->count());
        Queue::assertPushed(GenerateAuditReport::class);
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
            'price_per_unit' => 24900,
            'price_per_unit_after_discount' => 24900,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }

    /**
     * Mirrors orderFor() but for products that aren't seeded by
     * AuditMonetizationSeeder (e.g. an unrelated product slug), and
     * dispatches the real Ordered event rather than calling the listener
     * directly — some assertions here depend on the event's other
     * registered listeners actually running.
     */
    private function completeOrderFor(User $user, string $slug): void
    {
        $product = OneTimeProduct::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'description' => $slug, 'features' => []],
        );

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);
        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 100,
            'price_per_unit_after_discount' => 100,
            'discount_per_unit' => 0,
        ]);

        Ordered::dispatch($order);
    }
}
