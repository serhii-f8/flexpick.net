<?php

namespace Tests\Feature\Health;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Health\Checks\CardknoxDeclineRateCheck;
use App\Health\Checks\CardknoxPendingTransactionsCheck;
use App\Health\Checks\CardknoxRenewalBacklogCheck;
use App\Models\CardknoxCharge;
use App\Models\Subscription;
use App\Models\Transaction;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxChecksTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();

        // These checks read whole tables and FeatureTest never rolls back.
        // Neutralise rows left by other Cardknox tests instead of deleting
        // them (subscriptions and transactions have dependent rows).
        $providerId = $this->cardknoxProviderModel()->id;
        Transaction::query()->where('payment_provider_id', $providerId)
            ->update(['status' => TransactionStatus::SUCCESS->value, 'created_at' => now()->subYears(5)]);
        Subscription::query()->where('payment_provider_id', $providerId)
            ->update(['status' => SubscriptionStatus::CANCELED->value]);
    }

    public function test_backlog_fails_when_a_due_subscription_was_never_attempted(): void
    {
        $this->assertSame('ok', (new CardknoxRenewalBacklogCheck)->run()->status->value);

        $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => now()->subHours(3)]);

        $this->assertSame('failed', (new CardknoxRenewalBacklogCheck)->run()->status->value);
    }

    public function test_backlog_ignores_a_subscription_with_an_attempt_in_flight(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => now()->subHours(3)]);
        $base = 'S'.$subscription->id.'-'.now()->subHours(3)->format('Ymd');
        CardknoxCharge::create(['transaction_id' => $this->pendingTransactionFor($subscription)->id, 'invoice_base' => $base, 'invoice' => $base.'-1', 'purpose' => 'renewal']);

        $this->assertSame('ok', (new CardknoxRenewalBacklogCheck)->run()->status->value);
    }

    public function test_pending_check_fails_on_an_old_pending_transaction(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $transaction = $this->pendingTransactionFor($subscription);
        $this->assertSame('ok', (new CardknoxPendingTransactionsCheck)->run()->status->value);

        $transaction->forceFill(['created_at' => now()->subMinutes(61)])->saveQuietly();

        $this->assertSame('failed', (new CardknoxPendingTransactionsCheck)->run()->status->value);
    }

    public function test_decline_rate_fails_above_threshold_with_enough_samples(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());

        foreach ([TransactionStatus::FAILED, TransactionStatus::FAILED, TransactionStatus::FAILED, TransactionStatus::SUCCESS, TransactionStatus::SUCCESS] as $status) {
            $this->pendingTransactionFor($subscription)->update(['status' => $status->value]);
        }

        $this->assertSame('failed', (new CardknoxDeclineRateCheck)->run()->status->value);
    }
}
