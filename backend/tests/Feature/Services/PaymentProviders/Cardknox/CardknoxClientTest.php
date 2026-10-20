<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxClientTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    public function test_sale_sends_the_envelope_and_maps_approval(): void
    {
        $this->fakeGateway($this->gatewayApproves(['xRefNum' => '555']));

        $result = app(CardknoxClient::class)->sale('tok_1', 1234, 'S1-20261113-1', CardknoxClient::RECURRING);

        $this->assertSame(CardknoxOutcome::Approved, $result->outcome);
        $this->assertSame('555', $result->refNum);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://x1.cardknox.com/gatewayjson'
                && $request['xKey'] === 'test_transaction_key'
                && $request['xVersion'] === '5.0.0'
                && $request['xCommand'] === 'cc:Sale'
                && $request['xToken'] === 'tok_1'
                && $request['xAmount'] === '12.34'
                && $request['xInvoice'] === 'S1-20261113-1'
                && $request['xRecurringIndicator'] === 'Recurring';
        });
    }

    public function test_customer_initiated_sale_omits_recurring_indicator(): void
    {
        $this->fakeGateway($this->gatewayApproves());

        app(CardknoxClient::class)->sale('tok_1', 100, 'O1-1');

        Http::assertSent(fn (Request $request): bool => ! isset($request['xRecurringIndicator']));
    }

    public function test_decline_and_error_are_mapped(): void
    {
        $this->fakeGateway($this->gatewayDeclines('Do not honor'), ['xResult' => 'E', 'xError' => 'Invalid token']);

        $declined = app(CardknoxClient::class)->sale('tok', 100, 'a-1');
        $error = app(CardknoxClient::class)->sale('tok', 100, 'a-2');

        $this->assertSame(CardknoxOutcome::Declined, $declined->outcome);
        $this->assertSame('Do not honor', $declined->error);
        $this->assertSame(CardknoxOutcome::Error, $error->outcome);
    }

    // One Http::fake() per test: later fakes are appended and the first match wins.
    public function test_timeout_is_unknown_never_declined(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->sale('tok', 100, 'a-1')->outcome);
    }

    public function test_server_error_is_unknown_never_declined(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::response('oops', 502)]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->sale('tok', 100, 'a-2')->outcome);
    }

    public function test_save_passes_ifields_single_use_tokens(): void
    {
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'reusable_tok']));

        $result = app(CardknoxClient::class)->save('sut_card', 'sut_cvv', '1230');

        $this->assertSame('reusable_tok', $result->token);
        Http::assertSent(fn (Request $request): bool => $request['xCommand'] === 'cc:Save'
            && $request['xCardNum'] === 'sut_card'
            && $request['xCVV'] === 'sut_cvv'
            && $request['xExp'] === '1230');
    }

    public function test_find_by_invoice_distinguishes_missing_from_failed_lookup(): void
    {
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => [
            ['xInvoice' => 'other-1', 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '1'],
            ['xInvoice' => 'S9-F-1', 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '2'],
        ]], ['xResult' => 'A', 'xReportData' => []]);

        $found = app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour());
        $missing = app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour());

        $this->assertSame(CardknoxOutcome::Approved, $found->outcome);
        $this->assertSame('2', $found->refNum);
        $this->assertNull($missing);
        // The transaction endpoint rejects report commands, and report rows
        // carry xInvoice only when it is requested.
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://x1.cardknox.com/reportjson'
            && $request['xCommand'] === 'report:all'
            && str_contains($request['xFields'], 'xInvoice'));
    }

    public function test_failed_report_lookup_is_unknown(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour())->outcome);
    }

    public function test_pin_validation_and_completeness(): void
    {
        $this->assertTrue(CardknoxConfiguration::isValidPin('abcdefghijklmnop1'));
        $this->assertFalse(CardknoxConfiguration::isValidPin('short1'));
        $this->assertFalse(CardknoxConfiguration::isValidPin('has-dashes-in-it-123'));
        $this->assertTrue(app(CardknoxConfiguration::class)->isComplete());

        config(['services.cardknox.webhook_pin' => null]);
        $this->assertFalse(app(CardknoxConfiguration::class)->isComplete());
    }
}
