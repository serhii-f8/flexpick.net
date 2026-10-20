<?php

namespace Tests\Feature\Http\Controllers\PaymentProviders;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Jobs\ProcessCardknoxWebhook;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use App\Services\PaymentProviders\Cardknox\CardknoxWebhookHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxControllerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function postWebhook(array $fields, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

        if ($signature !== null) {
            $server['HTTP_CK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('payments-providers.cardknox.webhook'), [], [], [], $server, http_build_query($fields));
    }

    public function test_signature_algorithm_matches_the_documented_recipe(): void
    {
        // lowercase keys → sort → concatenate values → append PIN → MD5 → uppercase
        $fields = ['xResult' => 'A', 'xAmount' => '10.00', 'xRefNum' => '123'];
        $expected = strtoupper(md5('10.00'.'123'.'A'.'abcdefghijklmnop1'));

        $this->assertSame($expected, CardknoxWebhookHandler::signature($fields, 'abcdefghijklmnop1'));
    }

    public function test_valid_signature_is_accepted_and_queued_without_sensitive_fields(): void
    {
        Queue::fake();
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A', 'xRefNum' => '1', 'xInvoice' => 'S1-F-1', 'xToken' => 'secret-token', 'xMaskedCardNumber' => '4xxx1111'];

        $this->postWebhook($fields, CardknoxWebhookHandler::signature($fields, 'abcdefghijklmnop1'))->assertOk();

        Queue::assertPushed(ProcessCardknoxWebhook::class, fn (ProcessCardknoxWebhook $job): bool => $job->fields['xInvoice'] === 'S1-F-1'
            && ! array_key_exists('xToken', $job->fields)
            && ! array_key_exists('xMaskedCardNumber', $job->fields));
    }

    public function test_missing_or_wrong_signature_is_rejected(): void
    {
        Queue::fake();
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A'];

        $this->postWebhook($fields, null)->assertForbidden();
        $this->postWebhook($fields, 'DEADBEEF')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_endpoint_rejects_everything_when_no_valid_pin_is_configured(): void
    {
        Queue::fake();
        config(['services.cardknox.webhook_pin' => 'short']);
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A'];

        $this->postWebhook($fields, CardknoxWebhookHandler::signature($fields, 'short'))->assertForbidden();
    }

    public function test_sale_webhook_settles_a_pending_charge_once_even_if_delivered_twice(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        $charge = app(CardknoxChargeLedger::class)->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000);
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A', 'xRefNum' => '9001', 'xInvoice' => $charge->invoice];

        (new ProcessCardknoxWebhook($fields))->handle(app(CardknoxChargeLedger::class));
        (new ProcessCardknoxWebhook($fields))->handle(app(CardknoxChargeLedger::class));

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('9001', $charge->fresh()->ref_num);
        $this->assertSame(1, $charge->transaction()->count());
    }

    public function test_refund_webhook_refunds_the_order(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        (new ProcessCardknoxWebhook(['xCommand' => 'cc:Refund', 'xResult' => 'A', 'xRefNum' => '7', 'xInvoice' => $charge->invoice]))->handle($ledger);

        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
    }

    /** Sola's documented payload: the outcome is a word in xResponseResult, there is no xResult. */
    public function test_documented_webhook_payload_settles_the_charge(): void
    {
        Queue::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        $charge = app(CardknoxChargeLedger::class)->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000);
        $fields = [
            'xAmount' => '20.00',
            'xCommand' => 'cc:sale',
            'xEnteredDate' => '10/20/2026 5:27:14 AM',
            'xInvoice' => $charge->invoice,
            'xMaskedCardNumber' => '4xxxxxxxxxxx1111',
            'xRefNum' => '9002',
            'xResponseResult' => 'Approved',
        ];

        $this->postWebhook($fields, CardknoxWebhookHandler::signature($fields, 'abcdefghijklmnop1'))->assertOk();
        Queue::assertPushed(ProcessCardknoxWebhook::class, function (ProcessCardknoxWebhook $job): bool {
            $job->handle(app(CardknoxChargeLedger::class));

            return true;
        });

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('9002', $charge->fresh()->ref_num);
    }

    public function test_documented_refund_webhook_payload_refunds_the_order(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        (new ProcessCardknoxWebhook(['xCommand' => 'cc:refund', 'xResponseResult' => 'Approved', 'xRefNum' => '8', 'xInvoice' => $charge->invoice]))->handle($ledger);

        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
    }

    public function test_admin_refund_falls_back_to_void_only_on_a_definite_refusal(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves(['xRefNum' => '42'])));
        $this->fakeGateway(['xResult' => 'E', 'xError' => 'Transaction not settled'], $this->gatewayApproves());

        $result = app(CardknoxBillingService::class)->refund($charge->transaction->fresh());

        $this->assertTrue($result->isApproved());
        Http::assertSentCount(2);
        $this->assertSame(TransactionStatus::REFUNDED->value, $charge->transaction->fresh()->status);
    }
}
