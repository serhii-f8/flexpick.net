<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Events\Order\Ordered;
use App\Events\Order\OrderRefunded;
use App\Events\Subscription\InvoicePaymentFailed;
use App\Events\Subscription\SubscriptionRenewed;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxChargeLedgerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxChargeLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->ledger = app(CardknoxChargeLedger::class);
    }

    public function test_open_creates_a_pending_transaction_under_attempt_one(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000, 100);

        $this->assertSame('S'.$subscription->id.'-F-1', $charge->invoice);
        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->status);
        $this->assertSame(2000, (int) $charge->transaction->amount);
        $this->assertSame(100, (int) $charge->transaction->total_discount);
        $this->assertSame($charge->invoice, $charge->transaction->payment_provider_transaction_id);
    }

    public function test_open_refuses_while_an_attempt_is_pending_or_succeeded(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $base = 'S'.$subscription->id.'-F';

        $first = $this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000);
        $this->assertNull($this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000), 'pending blocks');

        $this->ledger->settle($first, CardknoxResult::fromResponse($this->gatewayDeclines()));
        $second = $this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000);
        $this->assertSame($base.'-2', $second->invoice, 'a failed attempt allows a new one');

        $this->ledger->settle($second, CardknoxResult::fromResponse($this->gatewayApproves()));
        $this->assertNull($this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000), 'success blocks');
    }

    public function test_execute_sends_a_sale_and_settles_first_charge(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        $this->fakeGateway($this->gatewayApproves(['xRefNum' => '777']));

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000, meta: [
            'activate' => ['quantity' => 3],
            'discount_ids' => [42],
        ]);
        $result = $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        $this->assertTrue($result->isApproved());
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertSame(3, (int) $fresh->quantity);
        $this->assertSame('2026-12-01 10:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString());
        $this->assertSame([42 => 1], $fresh->extra_payment_provider_data['discount_uses']);
        $this->assertSame('777', $charge->fresh()->ref_num);
        $this->assertSame(TransactionStatus::SUCCESS->value, $charge->transaction->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_zero_amount_settles_without_a_gateway_call(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 0);
        $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_unknown_result_changes_nothing(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000);
        $result = $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        $this->assertSame(CardknoxOutcome::Unknown, $result->outcome);
        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->fresh()->status);
        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->fresh()->status);
    }

    public function test_renewal_success_advances_once_and_consumes_credit(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'ends_at' => '2026-11-01 00:00:00',
            'extra_payment_provider_data' => ['pending_credit' => 700, 'failed_attempts' => 2, 'next_retry_at' => '2026-11-04 00:00:00'],
        ]);
        $periodEnd = Carbon::parse('2026-11-01 00:00:00');

        $charge = $this->ledger->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 1300, 0, $periodEnd, ['credit' => 700, 'discount_ids' => []]);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $fresh = $subscription->fresh();
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString());
        $this->assertSame(0, $fresh->extra_payment_provider_data['pending_credit']);
        $this->assertSame(0, $fresh->extra_payment_provider_data['failed_attempts']);
        $this->assertNull($fresh->extra_payment_provider_data['next_retry_at']);
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_settle_is_idempotent_and_late_settle_does_not_double_advance(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $charge = $this->ledger->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 2000, 0, Carbon::parse('2026-11-01 00:00:00'));
        $approved = CardknoxResult::fromResponse($this->gatewayApproves());

        $this->ledger->settle($charge, $approved);
        $this->ledger->settle($charge->fresh(), $approved);

        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($subscription->fresh()->ends_at)->toDateTimeString());
    }

    public function test_renewal_decline_walks_the_retry_ladder_then_cancels(): void
    {
        Event::fake([InvoicePaymentFailed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $base = 'S'.$subscription->id.'-20261101';
        $periodEnd = Carbon::parse('2026-11-01 00:00:00');
        $expectedRetries = ['2026-11-02 00:00:00', '2026-11-04 00:00:00', '2026-11-06 00:00:00'];

        foreach ($expectedRetries as $attempt => $retryAt) {
            $charge = $this->ledger->open($base, CardknoxChargePurpose::Renewal, $subscription->fresh(), 2000, 0, $periodEnd);
            $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayDeclines()));

            $fresh = $subscription->fresh();
            $this->assertSame(SubscriptionStatus::PAST_DUE->value, $fresh->status);
            $this->assertSame($attempt + 1, $fresh->extra_payment_provider_data['failed_attempts']);
            $this->assertSame($retryAt, $fresh->extra_payment_provider_data['next_retry_at']);
        }

        $charge = $this->ledger->open($base, CardknoxChargePurpose::Renewal, $subscription->fresh(), 2000, 0, $periodEnd);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayDeclines()));

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
        Event::assertDispatchedTimes(InvoicePaymentFailed::class, 4);
    }

    public function test_proration_success_applies_the_deferred_change(): void
    {
        $plan = $this->cardknoxPlan();
        $target = $this->cardknoxPlan(price: ['price' => 5000]);
        $subscription = $this->cardknoxSubscription($plan);

        $charge = $this->ledger->open('S'.$subscription->id.'-P1', CardknoxChargePurpose::Proration, $subscription, 900, meta: ['change' => ['plan_id' => $target->id, 'price' => 5000]]);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $this->assertSame($target->id, $subscription->fresh()->plan_id);
    }

    public function test_order_success_and_refund(): void
    {
        Event::fake([Ordered::class, OrderRefunded::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);

        $charge = $this->ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame($this->cardknoxProviderModel()->id, $order->fresh()->payment_provider_id);
        Event::assertDispatched(Ordered::class);

        $this->ledger->markRefunded($charge->fresh());
        $this->ledger->markRefunded($charge->fresh());

        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
        $this->assertSame(TransactionStatus::REFUNDED->value, $charge->transaction->fresh()->status);
        Event::assertDispatchedTimes(OrderRefunded::class, 1);
    }
}
