<?php

namespace Tests\Feature\Console;

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
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class IssueCashRenewalOrdersTest extends FeatureTest
{
    private function cashSubscription(array $attributes = []): Subscription
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

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
            'payment_provider_id' => $provider->id,
            'ends_at' => now()->addHours(24),
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 25],
        ], $attributes));
    }

    public function test_it_opens_a_renewal_order_inside_the_lead_window(): void
    {
        $subscription = $this->cashSubscription();

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $order = $subscription->orders()->firstOrFail();

        $this->assertSame(OrderType::RENEWAL->value, $order->type);
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(4900, (int) $order->total_amount);
        $this->assertSame(['audit_diagnostic_credits' => 25], $order->quota_snapshot);
    }

    public function test_it_does_not_open_a_second_renewal_order(): void
    {
        $subscription = $this->cashSubscription();

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();
        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(1, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_that_is_not_due_yet(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->addDays(20)]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_the_customer_already_cancelled(): void
    {
        $subscription = $this->cashSubscription(['is_canceled_at_end_of_cycle' => true]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_free_comped_local_subscription(): void
    {
        $subscription = $this->cashSubscription(['price' => 0]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_locally_managed_subscription_with_no_payment_provider(): void
    {
        $subscription = $this->cashSubscription(['payment_provider_id' => null]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_that_is_not_active(): void
    {
        $subscription = $this->cashSubscription(['status' => SubscriptionStatus::PAST_DUE->value]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_payment_provider_managed_subscription(): void
    {
        $subscription = $this->cashSubscription(['type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_created_before_the_sweep_floor(): void
    {
        $floor = Carbon::parse(config('cash_payments.sweep_from'));

        $subscription = $this->cashSubscription(['created_at' => $floor->copy()->subDay()]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }
}
