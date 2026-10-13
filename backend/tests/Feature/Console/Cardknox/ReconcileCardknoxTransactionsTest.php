<?php

namespace Tests\Feature\Console\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Events\Subscription\SubscriptionRenewed;
use App\Models\CardknoxCharge;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class ReconcileCardknoxTransactionsTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pendingRenewal(string $openedAt): CardknoxCharge
    {
        Carbon::setTestNow($openedAt);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $charge = app(CardknoxChargeLedger::class)->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 2000, 0, Carbon::parse('2026-11-01 00:00:00'));
        Carbon::setTestNow();

        return $charge;
    }

    private function reconcile(CardknoxCharge $charge): void
    {
        $this->artisan('app:reconcile-cardknox-transactions', ['--charge' => $charge->id])->assertSuccessful();
    }

    public function test_approved_at_the_gateway_settles_and_advances_the_period(): void
    {
        $charge = $this->pendingRenewal(now()->subHour()->toDateTimeString());
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => [['xInvoice' => $charge->invoice, 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '31']]]);

        $this->reconcile($charge);

        $this->assertSame(TransactionStatus::SUCCESS->value, $charge->transaction->fresh()->status);
        $this->assertSame('31', $charge->fresh()->ref_num);
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($charge->transaction->subscription->fresh()->ends_at)->toDateTimeString());
    }

    public function test_recent_charges_are_left_alone(): void
    {
        $charge = $this->pendingRenewal(now()->subMinutes(5)->toDateTimeString());
        Http::fake();

        $this->reconcile($charge);

        Http::assertNothingSent();
    }

    public function test_missing_record_waits_a_day_then_fails_into_the_retry_ladder(): void
    {
        $young = $this->pendingRenewal(now()->subHours(2)->toDateTimeString());
        $old = $this->pendingRenewal(now()->subHours(25)->toDateTimeString());
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => []], ['xResult' => 'A', 'xReportData' => []]);

        $this->reconcile($young);
        $this->reconcile($old);

        $this->assertSame(TransactionStatus::PENDING->value, $young->transaction->fresh()->status);
        $this->assertSame(TransactionStatus::FAILED->value, $old->transaction->fresh()->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $old->transaction->subscription->fresh()->status);
    }

    public function test_failed_lookup_changes_nothing(): void
    {
        $charge = $this->pendingRenewal(now()->subHours(30)->toDateTimeString());
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->reconcile($charge);

        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->fresh()->status);
    }

    public function test_one_failing_settle_does_not_stop_the_rest(): void
    {
        $first = $this->pendingRenewal(now()->subHour()->toDateTimeString());
        $second = $this->pendingRenewal(now()->subHour()->toDateTimeString());
        $failingId = $first->transaction->subscription_id;
        Event::listen(SubscriptionRenewed::class, function ($event) use ($failingId): void {
            if ($event->subscription->id === $failingId) {
                throw new \RuntimeException('listener blew up');
            }
        });
        $row = fn (CardknoxCharge $charge, string $ref): array => ['xInvoice' => $charge->invoice, 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => $ref];
        Http::fake(['x1.cardknox.com/*' => Http::response(['xResult' => 'A', 'xReportData' => [$row($first, '1'), $row($second, '2')]])]);

        app(CardknoxChargeLedger::class)->reconcilePending(now()->addMinute());

        $this->assertSame(TransactionStatus::SUCCESS->value, $second->transaction->fresh()->status);
    }

    public function test_lookup_window_reaches_back_far_enough_to_survive_a_timezone_offset(): void
    {
        $charge = $this->pendingRenewal(now()->subHour()->toDateTimeString());
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => []]);

        $this->reconcile($charge);

        Http::assertSent(fn ($request): bool => Carbon::parse($request['xBeginDate'])->lt($charge->created_at->copy()->subHours(12)));
    }
}
