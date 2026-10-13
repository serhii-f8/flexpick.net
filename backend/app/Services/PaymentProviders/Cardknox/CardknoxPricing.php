<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\Interval;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionDiscount;
use App\Models\SubscriptionUsage;
use Carbon\Carbon;

/**
 * Every amount Cardknox charges comes from here. Mirrors the pricing rules
 * CalculationService and Stripe's price objects apply, so a plan costs the
 * same whichever provider bills it.
 */
class CardknoxPricing
{
    public function planPrice(Plan $plan, int $currencyId): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->id)->where('currency_id', $currencyId)->firstOrFail();
    }

    public function recurringSubtotal(Plan $plan, int $currencyId, int $quantity): int
    {
        $price = $this->planPrice($plan, $currencyId);

        if ($plan->type === PlanType::SEAT_BASED->value) {
            if ($price->type === PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value) {
                $extraSeats = max(0, $quantity - (int) $price->included_seats);

                return (int) $price->price + $extraSeats * (int) $price->extra_seat_price;
            }

            return (int) $price->price * $quantity;
        }

        // Flat-rate plans, and the fixed fee of usage-based plans (usage is billed in arrears).
        return (int) $price->price;
    }

    public function usageAmount(PlanPrice $price, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        if ($price->type === PlanPriceType::USAGE_BASED_PER_UNIT->value) {
            return (int) round($units * (float) $price->price_per_unit);
        }

        $tiers = (array) ($price->tiers ?? []);

        if ($price->type === PlanPriceType::USAGE_BASED_TIERED_VOLUME->value) {
            foreach ($tiers as $tier) {
                if ($this->upperBound($tier) >= $units) {
                    return (int) round($units * (float) $tier[PlanPriceTierConstants::PER_UNIT] + (float) $tier[PlanPriceTierConstants::FLAT_FEE]);
                }
            }

            return 0;
        }

        // Graduated: each tier prices only the units that fall inside it.
        $total = 0.0;
        $lower = 0;

        foreach ($tiers as $tier) {
            $upper = $this->upperBound($tier);
            $inTier = min($units, $upper) - $lower;

            if ($inTier <= 0) {
                break;
            }

            $total += $inTier * (float) $tier[PlanPriceTierConstants::PER_UNIT] + (float) $tier[PlanPriceTierConstants::FLAT_FEE];
            $lower = $upper;
        }

        return (int) round($total);
    }

    /** Same formula as DiscountService::getDiscountAmount(), capped at the subtotal. */
    public function discountFor(string $type, int|float|string $amount, int $subtotal): int
    {
        return match ($type) {
            DiscountConstants::TYPE_FIXED => min($subtotal, (int) $amount),
            DiscountConstants::TYPE_PERCENTAGE => (int) ($subtotal * ((float) $amount / 100)),
            default => 0,
        };
    }

    public function renewalAmount(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): CardknoxAmount
    {
        $plan = Plan::query()->findOrFail($subscription->plan_id);
        $subtotal = $this->recurringSubtotal($plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $usage = 0;

        if ($plan->type === PlanType::USAGE_BASED->value) {
            $units = (int) SubscriptionUsage::query()
                ->where('subscription_id', $subscription->id)
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<', $periodEnd)
                ->sum('unit_count');

            $usage = $this->usageAmount($this->planPrice($plan, $subscription->currency_id), $units);
        }

        [$discount, $discountIds] = $this->activeDiscount($subscription, $subtotal + $usage, $periodStart);

        $due = $subtotal + $usage - $discount;
        $credit = min((int) ($subscription->extra_payment_provider_data['pending_credit'] ?? 0), $due);

        return new CardknoxAmount($subtotal, $usage, $discount, $credit, $due - $credit, $discountIds);
    }

    public function prorationAmount(Subscription $subscription, int $oldSubtotal, int $newSubtotal, Carbon $at): int
    {
        $end = Carbon::parse($subscription->ends_at);
        $start = $this->subInterval($subscription, $end);
        $period = max(1, (int) $start->diffInSeconds($end, true));
        $remaining = max(0, min($period, (int) $at->diffInSeconds($end, false)));

        return (int) round(($newSubtotal - $oldSubtotal) * ($remaining / $period));
    }

    public function addInterval(Subscription $subscription, Carbon $from): Carbon
    {
        // overflow=false: Jan 31 + 1 month is Feb 28/29, never Mar 3.
        return $from->copy()->add((string) Interval::query()->findOrFail($subscription->interval_id)->date_identifier, (int) $subscription->interval_count, false);
    }

    public function subInterval(Subscription $subscription, Carbon $from): Carbon
    {
        return $from->copy()->sub((string) Interval::query()->findOrFail($subscription->interval_id)->date_identifier, (int) $subscription->interval_count, false);
    }

    /** @return array{0: int, 1: list<int>} */
    private function activeDiscount(Subscription $subscription, int $base, Carbon $periodStart): array
    {
        $uses = (array) ($subscription->extra_payment_provider_data['discount_uses'] ?? []);
        $total = 0;
        $used = [];

        $rows = SubscriptionDiscount::query()->where('subscription_id', $subscription->id)->get();

        foreach ($rows as $row) {
            if ($row->valid_until !== null && Carbon::parse($row->valid_until)->lte($periodStart)) {
                continue;
            }

            $discount = Discount::query()->find($row->discount_id);
            $count = (int) ($uses[$row->discount_id] ?? 0);

            if (! $row->is_recurring && $count > 0) {
                continue;
            }

            if ($row->is_recurring && (int) ($discount?->maximum_recurring_intervals) > 0 && $count >= (int) $discount->maximum_recurring_intervals) {
                continue;
            }

            if ((int) ($discount?->duration_in_months) > 0 && $periodStart->gte(Carbon::parse($row->created_at)->addMonths((int) $discount->duration_in_months))) {
                continue;
            }

            $total += $this->discountFor((string) $row->type, $row->amount, $base - $total);
            $used[] = (int) $row->discount_id;
        }

        return [$total, $used];
    }

    private function upperBound(array $tier): int
    {
        $until = $tier[PlanPriceTierConstants::UNTIL_UNIT];

        return $until === '∞' ? PHP_INT_MAX : (int) $until;
    }
}
