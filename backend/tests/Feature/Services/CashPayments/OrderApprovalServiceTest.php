<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Services\CashPayments\OrderApprovalService;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class OrderApprovalServiceTest extends FeatureTest
{
    private function offlineProviderId(): int
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider->id;
    }

    private function pendingCashSubscription(): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::NEW->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProviderId(),
            'ends_at' => null,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ]);
    }

    private function pendingProductOrder(): Order
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'payment_provider_id' => $this->offlineProviderId(),
            'total_amount' => 9900,
            'base_price_snapshot' => 9900,
        ]);
    }

    public function test_approving_a_product_order_completes_it_and_writes_the_audit_row(): void
    {
        $order = $this->pendingProductOrder();
        $actor = $this->createUser();

        $result = app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER, $actor, 'Cash received.');

        $this->assertTrue($result);
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);

        $approval = OrderApproval::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(OrderApprovalActor::PARTNER->value, $approval->actor_type);
        $this->assertSame($actor->id, $approval->actor_user_id);
        $this->assertSame(OrderApprovalDecision::APPROVED->value, $approval->decision);
        $this->assertSame('Cash received.', $approval->note);
        $this->assertNotNull($approval->decided_at);
    }

    public function test_a_second_approval_is_a_silent_no_op(): void
    {
        $order = $this->pendingProductOrder();
        $service = app(OrderApprovalService::class);

        $this->assertTrue($service->approve($order, OrderApprovalActor::ADMIN));
        // Deliberately the stale in-memory $order (still PENDING in memory),
        // not $order->fresh(): the guard must trust the locked row it reads
        // for itself, not whatever status the caller happens to hand it.
        $this->assertFalse($service->approve($order, OrderApprovalActor::ADMIN));

        $this->assertSame(1, OrderApproval::where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_rejecting_marks_the_order_rejected_not_failed(): void
    {
        $order = $this->pendingProductOrder();

        $this->assertTrue(app(OrderApprovalService::class)->reject($order, OrderApprovalActor::ADMIN, null, 'No cash.'));

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
        $this->assertSame(
            OrderApprovalDecision::REJECTED->value,
            OrderApproval::where('order_id', $order->id)->value('decision'),
        );
    }

    public function test_approving_a_purchase_order_activates_the_subscription_for_one_interval(): void
    {
        $subscription = $this->pendingCashSubscription();
        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertSame(
            now()->addMonth()->toDateString(),
            Carbon::parse($subscription->ends_at)->toDateString(),
        );
    }

    public function test_approving_a_renewal_extends_from_the_existing_end_date(): void
    {
        $subscription = $this->pendingCashSubscription();
        $subscription->update([
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(2),
        ]);

        $order = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);

        app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER);

        $this->assertSame(
            now()->addDays(2)->addMonth()->toDateString(),
            Carbon::parse($subscription->fresh()->ends_at)->toDateString(),
        );
    }

    public function test_rejecting_a_purchase_order_cancels_the_pending_subscription(): void
    {
        $subscription = $this->pendingCashSubscription();
        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        app(OrderApprovalService::class)->reject($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_rejecting_a_renewal_leaves_the_paid_cycle_intact(): void
    {
        $subscription = $this->pendingCashSubscription();
        $endsAt = now()->addDays(2);
        $subscription->update(['status' => SubscriptionStatus::ACTIVE->value, 'ends_at' => $endsAt]);

        $order = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);

        app(OrderApprovalService::class)->reject($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertTrue((bool) $subscription->is_canceled_at_end_of_cycle);
        $this->assertSame($endsAt->toDateString(), Carbon::parse($subscription->ends_at)->toDateString());
    }

    public function test_it_recognises_a_pending_cash_order(): void
    {
        $service = app(OrderApprovalService::class);

        $this->assertTrue($service->isPendingCashOrder($this->pendingProductOrder()));

        $comped = $this->pendingProductOrder();
        $comped->update(['status' => OrderStatus::SUCCESS->value]);
        $this->assertFalse($service->isPendingCashOrder($comped->fresh()));

        $gateway = $this->pendingProductOrder();
        $gateway->update(['is_local' => false]);
        $this->assertFalse($service->isPendingCashOrder($gateway->fresh()));
    }

    public function test_approve_refuses_a_pending_order_that_is_not_local(): void
    {
        $order = $this->pendingProductOrder();
        $order->update(['is_local' => false]);

        $result = app(OrderApprovalService::class)->approve($order->fresh(), OrderApprovalActor::ADMIN);

        $this->assertFalse($result);
        $this->assertSame(OrderStatus::PENDING->value, $order->fresh()->status);
        $this->assertSame(0, OrderApproval::where('order_id', $order->id)->count());
    }

    public function test_approving_a_new_renewal_after_a_rejected_one_clears_the_sticky_cancel_flag(): void
    {
        $subscription = $this->pendingCashSubscription();
        $endsAt = now()->addDays(2);
        $subscription->update(['status' => SubscriptionStatus::ACTIVE->value, 'ends_at' => $endsAt]);

        $rejectedOrder = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);
        app(OrderApprovalService::class)->reject($rejectedOrder, OrderApprovalActor::PARTNER);

        $this->assertTrue((bool) $subscription->fresh()->is_canceled_at_end_of_cycle);

        $newOrder = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);
        app(OrderApprovalService::class)->approve($newOrder, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertFalse((bool) $subscription->is_canceled_at_end_of_cycle);
        $this->assertSame(
            $endsAt->copy()->addMonth()->toDateString(),
            Carbon::parse($subscription->ends_at)->toDateString(),
        );
    }

    public function test_approving_two_pending_renewals_in_sequence_extends_the_subscription_twice(): void
    {
        $subscription = $this->pendingCashSubscription();
        $endsAt = now()->addDays(2);
        $subscription->update(['status' => SubscriptionStatus::ACTIVE->value, 'ends_at' => $endsAt]);

        $firstOrder = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);
        $secondOrder = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);

        $service = app(OrderApprovalService::class);
        $this->assertTrue($service->approve($firstOrder, OrderApprovalActor::PARTNER));
        $this->assertTrue($service->approve($secondOrder, OrderApprovalActor::PARTNER));

        $this->assertSame(
            $endsAt->copy()->addMonths(2)->toDateString(),
            Carbon::parse($subscription->fresh()->ends_at)->toDateString(),
        );
    }
}
