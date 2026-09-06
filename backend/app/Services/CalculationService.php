<?php

namespace App\Services;

use App\Constants\DiscountConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Dto\CartDto;
use App\Dto\SubscriptionTotalsDto;
use App\Dto\TotalsDto;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Exception;

class CalculationService
{
    public function __construct(
        private PlanService $planService,
        private DiscountService $discountService,
        private OneTimeProductService $oneTimeProductService,
        private CurrencyService $currencyService,
    ) {}

    /**
     * Subscription price equals to the plan price
     */
    public function getPlanPrice(Plan $plan): PlanPrice
    {
        $currency = $this->currencyService->getCurrency();

        $planPrice = $plan->prices()->where('currency_id', $currency->id)->firstOrFail();

        return $planPrice;
    }

    public function getOneTimeProductPrice(OneTimeProduct $oneTimeProduct): OneTimeProductPrice
    {
        $currency = $this->currencyService->getCurrency();

        return $oneTimeProduct->prices()->where('currency_id', $currency->id)->firstOrFail();
    }

    /**
     * @param  bool  $allowPartnerPricing  False on the two checkout flows that
     *                                     terminate in a payment gateway rather than in cash — the local-trial
     *                                     signup and the convert-local-subscription flow. A gateway bills from
     *                                     getPlanPrice(), i.e. base (Decision 2), so those flows must not quote a
     *                                     partner price either, or the customer is shown one number and charged
     *                                     another. Passing the user through unchanged keeps user-scoped discount
     *                                     redemption limits working, which passing null would silently break.
     */
    public function calculatePlanTotals(?User $user, string $planSlug, ?string $discountCode = null, ?int $quantity = 1, string $actionType = DiscountConstants::ACTION_TYPE_ANY, bool $allowPartnerPricing = true): TotalsDto
    {
        $plan = $this->planService->getActivePlanBySlug($planSlug);

        if ($plan === null) {
            throw new Exception('Plan not found');
        }

        if ($discountCode !== null && ! $this->discountService->isCodeRedeemableForPlan($discountCode, $user, $plan, $actionType)) {
            throw new Exception('Discount code is not redeemable');
        }

        $planPrice = $this->getPlanPrice($plan);
        $currencyCode = $planPrice->currency->code;
        $totalsDto = new TotalsDto;

        $totalsDto->currencyCode = $currencyCode;

        $totalsDto->setupFee = $planPrice->setup_fee ?? 0;
        if ($plan->type === PlanType::SEAT_BASED->value) {
            if ($planPrice->type === PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value) {
                $extraSeats = max(0, $quantity - $planPrice->included_seats);
                $totalsDto->subtotal = $planPrice->price + ($extraSeats * $planPrice->extra_seat_price);
                $totalsDto->basePrice = $planPrice->price;
                $totalsDto->includedSeats = $planPrice->included_seats;
                $totalsDto->extraSeatPrice = $planPrice->extra_seat_price;
                $totalsDto->extraSeats = $extraSeats;
            } else {
                $totalsDto->subtotal = $planPrice->price * $quantity;
            }
        } else {
            // A partner-attributed buyer pays the partner's price for a flat-rate
            // plan (spec §8.1). Re-derived here from the resolver, never taken
            // from the request, which is what makes it untamperable. The base
            // $planPrice object is left untouched — gateway product sync reads it.
            //
            // Resolved lazily via the container rather than constructor-injected:
            // PartnerPricingResolver -> PartnerCapabilityService -> SubscriptionService
            // -> CalculationService is a real cycle (SubscriptionService already
            // depends on CalculationService), so constructor injection here causes
            // infinite recursion when the container builds this service. The same
            // problem, with the same fix, already exists at SubscriptionService.php:85
            // for PurchaseSnapshotService.
            $partnerPrice = $allowPartnerPricing
                ? app(PartnerPricingResolver::class)->planPrice($user, $plan)
                : null;

            $totalsDto->subtotal = $partnerPrice ?? $planPrice->price;
        }

        $totalsDto->discountAmount = 0;
        if ($discountCode !== null) {
            $totalsDto->discountAmount = $this->discountService->getDiscountAmount($discountCode, $totalsDto->subtotal);
        }

        $totalsDto->amountDue = max(0, $totalsDto->subtotal - $totalsDto->discountAmount) + $totalsDto->setupFee;

        $totalsDto->planPriceType = $planPrice->type;
        $totalsDto->pricePerUnit = $planPrice->price_per_unit;
        $totalsDto->tiers = $planPrice->tiers;

        return $totalsDto;
    }

    public function calculateNewPlanTotals(Subscription $subscription, string $planSlug, bool $withProration = false): TotalsDto
    {
        $newPlan = $this->planService->getActivePlanBySlug($planSlug);

        if ($newPlan === null) {
            throw new Exception('Plan not found');
        }

        $planPrice = $this->getPlanPrice($newPlan);
        $currencyCode = $planPrice->currency->code;
        $totalsDto = new SubscriptionTotalsDto;

        $totalsDto->currencyCode = $currencyCode;

        $totalsDto->discountAmount = 0;

        if ($newPlan->type === PlanType::SEAT_BASED->value) {
            $quantity = $subscription->tenant->users->count();
            if ($planPrice->type === PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value) {
                $extraSeats = max(0, $quantity - $planPrice->included_seats);
                $totalsDto->subtotal = $planPrice->price + ($extraSeats * $planPrice->extra_seat_price);
                $totalsDto->basePrice = $planPrice->price;
                $totalsDto->includedSeats = $planPrice->included_seats;
                $totalsDto->extraSeatPrice = $planPrice->extra_seat_price;
                $totalsDto->extraSeats = $extraSeats;
            } else {
                $totalsDto->subtotal = $planPrice->price * $quantity;
                $totalsDto->pricePerSeat = $planPrice->price;
            }
            $totalsDto->quantity = $quantity;
        } else {
            $totalsDto->subtotal = $planPrice->price;
        }

        if (! $withProration) {
            $totalsDto->amountDue = max(0, $totalsDto->subtotal - $totalsDto->discountAmount);
        }

        return $totalsDto;
    }

    public function calculateCartTotals(CartDto $cart, ?User $user): TotalsDto
    {
        $totalsDto = new TotalsDto;
        $totalsDto->currencyCode = $this->currencyService->getCurrency()->code;
        $currency = Currency::where('code', $totalsDto->currencyCode)->firstOrFail();

        $totalAmount = 0;
        $totalAmountAfterDiscount = 0;

        foreach ($cart->items as $item) {

            $product = $this->oneTimeProductService->getOneTimeProductById($item->productId);
            $productPrice = $product->prices()->where('currency_id', $currency->id)->firstOrFail();

            // A partner-attributed buyer pays the partner's price for this
            // one-time product (spec §8.1). Re-derived here from the resolver,
            // never taken from the cart, which is what makes it untamperable.
            //
            // Resolved lazily via the container rather than constructor-injected:
            // PartnerPricingResolver -> PartnerCapabilityService -> SubscriptionService
            // -> CalculationService is a real cycle (SubscriptionService already
            // depends on CalculationService), so constructor injection here causes
            // infinite recursion when the container builds this service. Same
            // fix as the flat-rate branch of calculatePlanTotals() above.
            $unitPrice = app(PartnerPricingResolver::class)->productPrice($user, $product) ?? (int) $productPrice->price;

            $totalAmount += $unitPrice * $item->quantity;

            $itemDiscountedPrice = $unitPrice;
            $discountCode = $cart->discountCode;
            if ($discountCode !== null && $this->discountService->isCodeRedeemableForOneTimeProduct($discountCode, $user, $product)) {
                $discountAmount = $this->discountService->getDiscountAmount($discountCode, $unitPrice);
                $itemDiscountedPrice = max(0, $unitPrice - $discountAmount);
            }

            $totalAmountAfterDiscount += $itemDiscountedPrice * $item->quantity;
        }

        $totalsDto->subtotal = $totalAmount;
        $totalsDto->amountDue = $totalAmountAfterDiscount;
        $totalsDto->discountAmount = max(0, $totalAmount - $totalAmountAfterDiscount);

        return $totalsDto;
    }

    public function calculateOrderTotals(Order $order, User $user, ?string $discountCode = null)
    {
        $currency = $this->currencyService->getCurrency();

        $totalAmount = 0;
        $totalAmountAfterDiscount = 0;

        $orderItems = $order->items()->get();

        foreach ($orderItems as $orderItem) {

            $product = $orderItem->oneTimeProduct()->firstOrFail();
            $productPrice = $product->prices()->where('currency_id', $currency->id)->firstOrFail();

            // Same rule as calculateCartTotals: this is the write side, and the
            // two must agree or the customer is quoted one price and charged
            // another. Resolved lazily via the container for the same
            // container-cycle reason documented above.
            $orderItem->price_per_unit = app(PartnerPricingResolver::class)->productPrice($user, $product) ?? (int) $productPrice->price;

            $totalAmount += $orderItem->price_per_unit * $orderItem->quantity;

            $itemDiscountedPrice = $orderItem->price_per_unit;
            if ($discountCode !== null && $this->discountService->isCodeRedeemableForOneTimeProduct($discountCode, $user, $product)) {
                $discountAmount = $this->discountService->getDiscountAmount($discountCode, $orderItem->price_per_unit);
                $itemDiscountedPrice = max(0, $orderItem->price_per_unit - $discountAmount);
            }

            $orderItem->price_per_unit_after_discount = $itemDiscountedPrice;
            $orderItem->discount_per_unit = max(0, $orderItem->price_per_unit - $itemDiscountedPrice);

            $totalAmountAfterDiscount += $itemDiscountedPrice * $orderItem->quantity;
            $orderItem->currency_id = $currency->id;

            $orderItem->save();
        }

        $order->total_amount = $totalAmount;
        $order->total_amount_after_discount = $totalAmountAfterDiscount;
        $order->total_discount_amount = max(0, $totalAmount - $totalAmountAfterDiscount);
        $order->currency_id = $currency->id;

        $order->save();
    }
}
