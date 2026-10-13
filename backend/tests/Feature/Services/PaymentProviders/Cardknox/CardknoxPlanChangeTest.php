<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PlanType;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPlanChangeTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->billing = app(CardknoxBillingService::class);
        // Period 2026-11-01 → 2026-12-01 (30 days), 15 days remain.
        Carbon::setTestNow('2026-11-16 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prorated_upgrade_charges_the_difference_now_and_switches(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 5000]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: true));

        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '10.00' && ! isset($request['xRecurringIndicator']));
        $fresh = $subscription->fresh();
        $this->assertSame($target->id, $fresh->plan_id);
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString(), 'billing period unchanged');
    }

    public function test_declined_upgrade_keeps_the_old_plan(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 3000]);
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00']);
        $this->fakeGateway($this->gatewayDeclines());

        $this->assertFalse($this->billing->changePlan($subscription, $this->cardknoxPlan(price: ['price' => 5000]), prorate: true));

        $this->assertSame($plan->id, $subscription->fresh()->plan_id);
    }

    public function test_prorated_downgrade_banks_credit_without_a_refund(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 2000]);

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: true));

        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame($target->id, $fresh->plan_id);
        $this->assertSame(500, $fresh->extra_payment_provider_data['pending_credit']);
    }

    public function test_unprorated_change_switches_immediately_without_charging(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 9000]);

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: false));

        Http::assertNothingSent();
        $this->assertSame($target->id, $subscription->fresh()->plan_id);
        $this->assertSame(9000, (int) $subscription->fresh()->price);
    }

    public function test_changes_during_a_trial_are_never_prorated(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-11-20 00:00:00', 'trial_ends_at' => '2026-11-20 00:00:00']);

        $this->assertTrue($this->billing->changePlan($subscription, $this->cardknoxPlan(price: ['price' => 9000]), prorate: true));

        Http::assertNothingSent();
    }

    public function test_prorated_seat_increase_charges_for_the_added_seats(): void
    {
        $plan = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], ['price' => 1000]);
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00', 'quantity' => 2]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertTrue($this->billing->changeQuantity($subscription, 4, prorate: true));

        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '10.00');
        $this->assertSame(4, (int) $subscription->fresh()->quantity);
    }
}
