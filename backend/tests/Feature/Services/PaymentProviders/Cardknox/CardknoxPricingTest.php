<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\PlanPriceTierConstants as T;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\SubscriptionDiscount;
use App\Models\SubscriptionUsage;
use App\Services\PaymentProviders\Cardknox\CardknoxPricing;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPricingTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxPricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->pricing = app(CardknoxPricing::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const TIERS = [
        [T::UNTIL_UNIT => '10', T::PER_UNIT => '100', T::FLAT_FEE => '0'],
        [T::UNTIL_UNIT => '∞', T::PER_UNIT => '50', T::FLAT_FEE => '200'],
    ];

    public function test_flat_rate_subtotal(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2500]);

        $this->assertSame(2500, $this->pricing->recurringSubtotal($plan, $plan->prices()->first()->currency_id, 1));
    }

    public function test_seat_based_subtotals(): void
    {
        $perSeat = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], ['price' => 1000]);
        $included = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], [
            'price' => 5000,
            'type' => PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value,
            'included_seats' => 3,
            'extra_seat_price' => 700,
        ]);
        $usd = $perSeat->prices()->first()->currency_id;

        $this->assertSame(4000, $this->pricing->recurringSubtotal($perSeat, $usd, 4));
        $this->assertSame(5000, $this->pricing->recurringSubtotal($included, $usd, 2));
        $this->assertSame(5000 + 2 * 700, $this->pricing->recurringSubtotal($included, $usd, 5));
    }

    public function test_usage_per_unit_volume_and_graduated(): void
    {
        $perUnit = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_PER_UNIT->value, 'price_per_unit' => '25']);
        $volume = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_TIERED_VOLUME->value, 'tiers' => self::TIERS]);
        $graduated = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_TIERED_GRADUATED->value, 'tiers' => self::TIERS]);

        $this->assertSame(250, $this->pricing->usageAmount($perUnit->prices()->first(), 10));
        $this->assertSame(800, $this->pricing->usageAmount($volume->prices()->first(), 8));
        $this->assertSame(15 * 50 + 200, $this->pricing->usageAmount($volume->prices()->first(), 15));
        $this->assertSame(10 * 100 + 5 * 50 + 200, $this->pricing->usageAmount($graduated->prices()->first(), 15));
        $this->assertSame(0, $this->pricing->usageAmount($graduated->prices()->first(), 0));
    }

    public function test_renewal_adds_usage_of_the_period_only(): void
    {
        $plan = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['price' => 1000, 'type' => PlanPriceType::USAGE_BASED_PER_UNIT->value, 'price_per_unit' => '10']);
        $subscription = $this->cardknoxSubscription($plan);
        $start = now()->subMonth();
        $end = now();

        SubscriptionUsage::create(['subscription_id' => $subscription->id, 'unit_count' => 7])->forceFill(['created_at' => $start->copy()->addDay()])->save();
        SubscriptionUsage::create(['subscription_id' => $subscription->id, 'unit_count' => 99])->forceFill(['created_at' => $start->copy()->subDay()])->save();

        $amount = $this->pricing->renewalAmount($subscription, $start, $end);

        $this->assertSame(1000, $amount->subtotal);
        $this->assertSame(70, $amount->usage);
        $this->assertSame(1070, $amount->total);
    }

    public function test_discount_rules(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan);
        $percent = Discount::create(['name' => 'p', 'type' => DiscountConstants::TYPE_PERCENTAGE, 'amount' => 25, 'is_active' => true, 'is_recurring' => true, 'maximum_recurring_intervals' => 2]);
        $once = Discount::create(['name' => 'o', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 300, 'is_active' => true, 'is_recurring' => false]);

        foreach ([$percent, $once] as $discount) {
            SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $discount->id, 'type' => $discount->type, 'amount' => $discount->amount, 'is_recurring' => $discount->is_recurring]);
        }

        $first = $this->pricing->renewalAmount($subscription, now()->subMonth(), now());
        $this->assertSame(500 + 300, $first->discount);
        $this->assertEqualsCanonicalizing([$percent->id, $once->id], $first->discountIdsUsed);

        $subscription->update(['extra_payment_provider_data' => ['discount_uses' => [$percent->id => 1, $once->id => 1]]]);
        $second = $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now());
        $this->assertSame(500, $second->discount, 'one-time discount is spent, recurring one has one interval left');

        $subscription->update(['extra_payment_provider_data' => ['discount_uses' => [$percent->id => 2, $once->id => 1]]]);
        $this->assertSame(0, $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now())->discount);
    }

    public function test_discount_duration_and_valid_until_expire(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan);
        $months = Discount::create(['name' => 'm', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 100, 'is_active' => true, 'is_recurring' => true, 'duration_in_months' => 1]);
        $expired = Discount::create(['name' => 'e', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 100, 'is_active' => true, 'is_recurring' => true]);

        SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $months->id, 'type' => 'fixed', 'amount' => 100, 'is_recurring' => true]);
        SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $expired->id, 'type' => 'fixed', 'amount' => 100, 'is_recurring' => true, 'valid_until' => now()->subDay()]);

        $this->assertSame(100, $this->pricing->renewalAmount($subscription, now(), now()->addMonth())->discount);
        $this->assertSame(0, $this->pricing->renewalAmount($subscription, now()->addMonths(2), now()->addMonths(3))->discount);
    }

    public function test_pending_credit_is_deducted_but_never_below_zero(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan, ['extra_payment_provider_data' => ['pending_credit' => 500]]);

        $amount = $this->pricing->renewalAmount($subscription, now()->subMonth(), now());
        $this->assertSame(500, $amount->credit);
        $this->assertSame(1500, $amount->total);

        $subscription->update(['extra_payment_provider_data' => ['pending_credit' => 5000]]);
        $big = $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now());
        $this->assertSame(2000, $big->credit);
        $this->assertSame(0, $big->total);
    }

    public function test_proration_is_signed_and_proportional(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00');
        $plan = $this->cardknoxPlan(price: ['price' => 3000]);
        // Period 2026-11-01 → 2026-12-01 (30 days); 15 days remain.
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00']);

        $this->assertSame(1000, $this->pricing->prorationAmount($subscription, 3000, 5000, now()));
        $this->assertSame(-500, $this->pricing->prorationAmount($subscription, 3000, 2000, now()));
    }

    public function test_add_interval_does_not_overflow_month_end(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan);

        $this->assertSame('2027-02-28', $this->pricing->addInterval($subscription, Carbon::parse('2027-01-31'))->toDateString());
        $this->assertSame('2027-02-28', $this->pricing->subInterval($subscription, Carbon::parse('2027-03-31'))->toDateString());
    }
}
