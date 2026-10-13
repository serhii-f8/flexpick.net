<?php

namespace Tests\Support;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\UserCardknoxData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared fixtures for Cardknox tests. FeatureTest never rolls back, so every
 * helper creates uniquely-keyed rows, and tearDownCardknoxTestHelpers() (called
 * by Laravel's trait lifecycle) puts the provider back to inactive so other
 * suites' checkout pages are unaffected.
 */
trait CardknoxTestHelpers
{
    protected function tearDownCardknoxTestHelpers(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)
            ->update(['is_active' => false, 'is_enabled_for_new_payments' => false]);

        // Tests that freeze time into the future (Carbon::setTestNow) stamp rows
        // with future created_at values. FeatureTest never rolls back, so those
        // rows would become "latest()" for every later suite. Runs after the
        // test has restored the real clock.
        foreach (['subscriptions', 'transactions', 'orders'] as $table) {
            DB::table($table)->where('created_at', '>', now())->update(['created_at' => now()->subYear()]);
        }
    }

    protected function configureCardknox(): void
    {
        config([
            'services.cardknox.transaction_key' => 'test_transaction_key',
            'services.cardknox.ifields_key' => 'ifields_test_key',
            'services.cardknox.webhook_pin' => 'abcdefghijklmnop1',
            'services.cardknox.gateway_url' => 'https://x1.cardknox.com',
            'services.cardknox.retry_days' => [1, 3, 5],
        ]);

        PaymentProvider::firstOrCreate(
            ['slug' => PaymentProviderConstants::CARDKNOX_SLUG],
            ['name' => 'Cardknox', 'type' => 'multi', 'sort' => 7],
        )->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
    }

    protected function cardknoxProviderModel(): PaymentProvider
    {
        return PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();
    }

    protected function gatewayApproves(array $overrides = []): array
    {
        return array_merge([
            'xResult' => 'A',
            'xStatus' => 'Approved',
            'xRefNum' => (string) random_int(100000000, 999999999),
            'xToken' => 'tok_'.Str::random(12),
            'xMaskedCardNumber' => '4xxxxxxxxxxx1111',
            'xCardType' => 'Visa',
            'xExp' => '1230',
            'xError' => '',
        ], $overrides);
    }

    protected function gatewayDeclines(string $error = 'Insufficient funds'): array
    {
        return [
            'xResult' => 'D',
            'xStatus' => 'Declined',
            'xRefNum' => (string) random_int(100000000, 999999999),
            'xError' => $error,
        ];
    }

    /** Queue gateway responses in order; any extra request fails the test loudly. */
    protected function fakeGateway(array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push($response);
        }

        $sequence->whenEmpty(Http::response(['unexpected' => 'extra gateway call'], 500));

        Http::fake(['x1.cardknox.com/*' => $sequence]);
    }

    protected function cardknoxPlan(array $plan = [], array $price = []): Plan
    {
        $model = Plan::factory()->create(array_merge([
            'slug' => 'ckx-'.Str::random(10),
            'type' => PlanType::FLAT_RATE->value,
            'interval_id' => Interval::where('slug', 'month')->firstOrFail()->id,
            'interval_count' => 1,
            'has_trial' => false,
            'is_active' => true,
            // FeatureTest never rolls back: keep these out of paginated public plan lists.
            'is_visible' => false,
        ], $plan));

        PlanPrice::factory()->create(array_merge([
            'plan_id' => $model->id,
            'currency_id' => Currency::where('code', 'USD')->firstOrFail()->id,
            'price' => 2000,
            'setup_fee' => 0,
        ], $price));

        return $model;
    }

    /** A Cardknox subscription with a card on file, due by default in 30 days. */
    protected function cardknoxSubscription(Plan $plan, array $attributes = [], bool $withCard = true): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = Subscription::factory()->create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => $plan->prices()->first()->price,
            'interval_id' => $plan->interval_id,
            'interval_count' => $plan->interval_count,
            'quantity' => 1,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'payment_provider_id' => $this->cardknoxProviderModel()->id,
            'payment_provider_subscription_id' => 'ckx_sub_'.Str::uuid(),
            'ends_at' => now()->addDays(30),
            'extra_payment_provider_data' => [],
        ], $attributes));

        if ($withCard) {
            UserCardknoxData::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'card_token' => 'tok_on_file_'.Str::random(8),
                'card_brand' => 'Visa',
                'card_last4' => '1111',
                'card_exp' => '1230',
            ]);
        }

        return $subscription->fresh();
    }

    protected function pendingTransactionFor(Subscription $subscription): Transaction
    {
        return Transaction::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $subscription->user_id,
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'currency_id' => $subscription->currency_id,
            'amount' => 2000,
            'total_tax' => 0,
            'total_discount' => 0,
            'total_fees' => 0,
            'status' => TransactionStatus::PENDING->value,
            'payment_provider_id' => $this->cardknoxProviderModel()->id,
            'payment_provider_status' => 'pending',
            'payment_provider_transaction_id' => 'ckx-'.Str::random(10),
        ]);
    }
}
