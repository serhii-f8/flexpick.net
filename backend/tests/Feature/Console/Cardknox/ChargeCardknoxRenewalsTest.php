<?php

namespace Tests\Feature\Console\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Events\Subscription\SubscriptionRenewed;
use App\Models\CardknoxCharge;
use App\Models\Transaction;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class ChargeCardknoxRenewalsTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        Carbon::setTestNow('2026-11-01 01:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runFor(string $uuid): void
    {
        // --subscription scopes the run to this test's row; other tests share the DB.
        $this->artisan('app:charge-cardknox-renewals', ['--subscription' => $uuid])->assertSuccessful();
    }

    public function test_due_subscription_is_charged_with_the_recurring_indicator_and_advanced(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $this->fakeGateway($this->gatewayApproves());

        $this->runFor($subscription->uuid);

        Http::assertSent(fn (Request $request): bool => $request['xRecurringIndicator'] === 'Recurring'
            && $request['xInvoice'] === 'S'.$subscription->id.'-20261101-1'
            && $request['xAmount'] === '20.00');
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($subscription->fresh()->ends_at)->toDateTimeString());
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_running_twice_charges_once(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $this->fakeGateway($this->gatewayApproves());

        $this->runFor($subscription->uuid);
        $this->runFor($subscription->uuid);

        Http::assertSentCount(1);
    }

    public function test_existing_pending_charge_blocks_a_new_one(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $transaction = $this->pendingTransactionFor($subscription);
        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'S'.$subscription->id.'-20261101', 'invoice' => 'S'.$subscription->id.'-20261101-1', 'purpose' => 'renewal', 'period_end' => '2026-11-01 00:00:00']);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
    }

    public function test_not_yet_due_is_skipped(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-02 00:00:00']);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
    }

    public function test_past_due_waits_for_next_retry_at(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => '2026-10-31 00:00:00',
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => '2026-11-01 00:00:00'],
        ]);
        $this->fakeGateway($this->gatewayApproves());

        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->runFor($subscription->uuid);
        Http::assertNothingSent();

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->runFor($subscription->uuid);
        Http::assertSentCount(1);
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_cancel_at_end_of_cycle_cancels_instead_of_charging(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00', 'is_canceled_at_end_of_cycle' => true]);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
    }

    public function test_missing_card_counts_as_a_failed_attempt(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00'], withCard: false);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $subscription->fresh()->status);
        $this->assertSame(TransactionStatus::FAILED->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
    }

    public function test_timeout_stays_pending_and_is_not_retried(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->runFor($subscription->uuid);
        $this->runFor($subscription->uuid);

        Http::assertSentCount(1);
        $this->assertSame(TransactionStatus::PENDING->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_retry_outstanding_skips_the_wait(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => '2026-10-31 00:00:00',
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => '2026-11-05 00:00:00'],
        ]);
        $this->fakeGateway($this->gatewayApproves());

        $result = app(CardknoxBillingService::class)->retryOutstanding($subscription);

        $this->assertTrue($result->isApproved());
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_local_subscription_sweep_never_touches_cardknox_subscriptions(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-10-01 00:00:00']);

        app(SubscriptionService::class)->cleanupLocalSubscriptionStatuses();
        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame(0, $subscription->orders()->count());
    }
}
