<?php

namespace Tests\Feature\Console;

use App\Constants\OrderApprovalActor;
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
use App\Services\SubscriptionService;
use Tests\Feature\FeatureTest;

class ExpirePendingCashOrdersTest extends FeatureTest
{
    private function offlineProviderId(): int
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider->id;
    }

    private function cashSubscription(array $attributes = []): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProviderId(),
            'ends_at' => now()->addDays(30),
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ], $attributes));
    }

    public function test_a_stale_purchase_order_is_rejected_by_the_system(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $stale = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
            'type' => OrderType::PURCHASE->value,
            'created_at' => now()->subHours(100),
        ]);

        $fresh = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
            'type' => OrderType::PURCHASE->value,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::REJECTED->value, $stale->fresh()->status);
        $this->assertSame(OrderStatus::PENDING->value, $fresh->fresh()->status);
        $this->assertSame(
            OrderApprovalActor::SYSTEM->value,
            OrderApproval::where('order_id', $stale->id)->value('actor_type'),
        );
    }

    public function test_expiring_a_purchase_order_cancels_its_pending_subscription(): void
    {
        $subscription = $this->cashSubscription([
            'status' => SubscriptionStatus::NEW->value,
            'ends_at' => null,
        ]);

        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);
        $order->forceFill(['created_at' => now()->subHours(100)])->save();

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
    }

    public function test_a_cash_subscription_past_its_end_date_goes_past_due(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->subHour()]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $subscription->fresh()->status);
    }

    public function test_a_past_due_cash_subscription_is_cancelled_after_the_grace_window(): void
    {
        $subscription = $this->cashSubscription([
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subHours(100),
        ]);

        $renewal = app(CashSubscriptionService::class)->createPendingOrder($subscription, OrderType::RENEWAL);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
        $this->assertSame(OrderStatus::REJECTED->value, $renewal->fresh()->status);
    }

    public function test_a_renewal_order_is_not_expired_on_age_alone(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->addDays(10)]);

        $renewal = app(CashSubscriptionService::class)->createPendingOrder($subscription, OrderType::RENEWAL);
        $renewal->forceFill(['created_at' => now()->subHours(100)])->save();

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::PENDING->value, $renewal->fresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_a_flagged_cash_subscription_is_cancelled_without_a_grace_window(): void
    {
        $subscription = $this->cashSubscription([
            'is_canceled_at_end_of_cycle' => true,
            'ends_at' => now()->subHour(),
        ]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
    }

    public function test_the_local_cleanup_sweep_leaves_cash_subscriptions_alone(): void
    {
        $cash = $this->cashSubscription(['ends_at' => now()->subHour()]);

        $compedOnOfflineProvider = $this->cashSubscription([
            'price' => 0,
            'ends_at' => now()->subHour(),
        ]);

        $paidWithNoProvider = $this->cashSubscription([
            'payment_provider_id' => null,
            'ends_at' => now()->subHour(),
        ]);

        app(SubscriptionService::class)->cleanupLocalSubscriptionStatuses();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $cash->fresh()->status);
        $this->assertSame(SubscriptionStatus::INACTIVE->value, $compedOnOfflineProvider->fresh()->status);
        $this->assertSame(SubscriptionStatus::INACTIVE->value, $paidWithNoProvider->fresh()->status);
    }
}
