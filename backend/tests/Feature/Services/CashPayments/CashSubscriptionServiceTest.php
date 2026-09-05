<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Services\PaymentProviders\Offline\OfflineProvider;
use Tests\Feature\FeatureTest;

class CashSubscriptionServiceTest extends FeatureTest
{
    private function offlineProvider(): PaymentProvider
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider;
    }

    private function cashSubscription(int $price = 4900): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => $price,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::NEW->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProvider()->id,
            'ends_at' => null,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ]);
    }

    public function test_a_paid_offline_subscription_is_a_cash_subscription(): void
    {
        $service = app(CashSubscriptionService::class);

        $this->assertTrue($service->isCashSubscription($this->cashSubscription()));
        $this->assertFalse($service->isCashSubscription($this->cashSubscription(0)));
    }

    public function test_starting_one_parks_the_subscription_and_opens_a_purchase_order(): void
    {
        $subscription = $this->cashSubscription();

        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        $this->assertSame(SubscriptionStatus::PENDING->value, $subscription->fresh()->status);
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(OrderType::PURCHASE->value, $order->type);
        $this->assertSame($subscription->id, $order->subscription_id);
        $this->assertSame(4900, (int) $order->total_amount);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 10], $order->fresh()->quota_snapshot);
        $this->assertTrue((bool) $order->is_local);
    }

    public function test_the_offline_provider_starts_it_during_checkout(): void
    {
        $subscription = $this->cashSubscription();
        $subscription->update(['type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED, 'payment_provider_id' => null]);

        app(OfflineProvider::class)->initSubscriptionCheckout($subscription->plan, $subscription);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PENDING->value, $subscription->status);
        $this->assertSame(1, $subscription->orders()->where('status', OrderStatus::PENDING->value)->count());
    }

    public function test_a_free_offline_subscription_is_left_exactly_as_before(): void
    {
        $subscription = $this->cashSubscription(0);
        $subscription->update(['type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED, 'payment_provider_id' => null]);

        app(OfflineProvider::class)->initSubscriptionCheckout($subscription->plan, $subscription);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->status);
        $this->assertSame(0, $subscription->orders()->count());
    }
}
