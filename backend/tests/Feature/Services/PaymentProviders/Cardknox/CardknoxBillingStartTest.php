<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Interval;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\UserCardknoxData;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxCardVault;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxBillingStartTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->billing = app(CardknoxBillingService::class);
    }

    public function test_vault_tokenizes_and_remembers_one_card_per_tenant_user(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), withCard: false);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_a']), $this->gatewayApproves(['xToken' => 'tok_b', 'xMaskedCardNumber' => '5xxxxxxxxxxx4444']));
        $vault = app(CardknoxCardVault::class);

        $vault->remember($subscription->user, $subscription->tenant, $vault->tokenize('sut', 'sut', '1230'), '1230');
        $vault->remember($subscription->user, $subscription->tenant, $vault->tokenize('sut', 'sut', '0131'), '0131');

        $card = $vault->cardFor($subscription->tenant_id, $subscription->user_id);
        $this->assertSame('tok_b', $card->card_token);
        $this->assertSame('4444', $card->card_last4);
        $this->assertSame(1, UserCardknoxData::where('user_id', $subscription->user_id)->count());
    }

    public function test_trial_saves_card_and_charges_nothing(): void
    {
        Http::fake();
        $trialDay = Interval::where('slug', 'day')->firstOrFail();
        $plan = $this->cardknoxPlan(['has_trial' => true, 'trial_interval_id' => $trialDay->id, 'trial_interval_count' => 14]);
        $subscription = $this->cardknoxSubscription($plan, ['status' => SubscriptionStatus::NEW->value, 'ends_at' => null]);

        $result = $this->billing->startSubscription($subscription, 1, null);

        $this->assertTrue($result->isApproved());
        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertNotNull($fresh->trial_ends_at);
        $this->assertEquals($fresh->trial_ends_at, $fresh->ends_at);
        $this->assertSame(0, Transaction::where('subscription_id', $subscription->id)->count());
    }

    public function test_first_charge_includes_quantity_discount_and_setup_fee(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000, 'setup_fee' => 500]);
        $subscription = $this->cardknoxSubscription($plan, ['status' => SubscriptionStatus::NEW->value]);
        $discount = Discount::create(['name' => 'ten', 'type' => DiscountConstants::TYPE_PERCENTAGE, 'amount' => 10, 'is_active' => true, 'is_recurring' => false]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertSame(2000 - 200 + 500, $this->billing->quoteFirstCharge($subscription, 1, $discount));

        $result = $this->billing->startSubscription($subscription, 1, $discount);

        $this->assertTrue($result->isApproved());
        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '23.00' && $request['xInvoice'] === 'S'.$subscription->id.'-F-1');
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertSame('ckx_sub_'.$subscription->uuid, $fresh->payment_provider_subscription_id);
        $this->assertSame([$discount->id => 1], $fresh->extra_payment_provider_data['discount_uses']);
        $this->assertSame(200, (int) Transaction::where('subscription_id', $subscription->id)->value('total_discount'));
    }

    public function test_declined_first_charge_leaves_subscription_unchanged(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => null,
            'payment_provider_subscription_id' => null,
        ]);
        $this->fakeGateway($this->gatewayDeclines());

        $result = $this->billing->startSubscription($subscription, 1, null);

        $this->assertSame(CardknoxOutcome::Declined, $result->outcome);
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionType::LOCALLY_MANAGED, $fresh->type);
        $this->assertNull($fresh->payment_provider_id);
        $this->assertSame(TransactionStatus::FAILED->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
    }

    public function test_convert_local_subscription_is_charged_and_becomes_provider_managed(): void
    {
        $plan = $this->cardknoxPlan(['has_trial' => true, 'trial_interval_id' => Interval::where('slug', 'day')->firstOrFail()->id, 'trial_interval_count' => 14]);
        $subscription = $this->cardknoxSubscription($plan, [
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => null,
            'payment_provider_subscription_id' => null,
        ]);
        $this->fakeGateway($this->gatewayApproves());

        $this->billing->startSubscription($subscription, 1, null);

        Http::assertSentCount(1);
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionType::PAYMENT_PROVIDER_MANAGED, $fresh->type);
        $this->assertSame($this->cardknoxProviderModel()->id, $fresh->payment_provider_id);
    }

    public function test_pay_order_charges_the_order_total(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 11900, 'total_amount_after_discount' => 9900, 'total_discount_amount' => 2000]);
        $this->fakeGateway($this->gatewayApproves());

        $result = $this->billing->payOrder($order, 'tok_once');

        $this->assertTrue($result->isApproved());
        Http::assertSent(fn (Request $request): bool => $request['xToken'] === 'tok_once' && $request['xAmount'] === '99.00' && $request['xInvoice'] === 'O'.$order->id.'-1');
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_non_usd_is_refused_before_the_gateway(): void
    {
        Http::fake();
        $eur = Currency::where('code', 'EUR')->first() ?? Currency::factory()->create(['code' => 'EUR']);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'currency_id' => $eur->id, 'total_amount' => 100, 'total_amount_after_discount' => 100, 'total_discount_amount' => 0]);

        $this->assertSame(CardknoxOutcome::Error, $this->billing->payOrder($order, 'tok')->outcome);
        Http::assertNothingSent();
    }
}
