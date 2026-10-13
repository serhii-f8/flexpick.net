<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\SubscriptionService;

/**
 * Decides what to charge and when. Every gateway call goes through
 * CardknoxChargeLedger, which owns idempotency and state transitions.
 */
class CardknoxBillingService
{
    public function __construct(
        private CardknoxChargeLedger $ledger,
        private CardknoxPricing $pricing,
        private CardknoxCardVault $vault,
        private SubscriptionService $subscriptionService,
    ) {}

    public function quoteFirstCharge(Subscription $subscription, int $quantity, ?Discount $discount): int
    {
        return $this->startsWithTrial($subscription) ? 0 : $this->firstCharge($subscription, $quantity, $discount)['amount'];
    }

    /** The card must already be remembered in the vault. */
    public function startSubscription(Subscription $subscription, int $quantity, ?Discount $discount): CardknoxResult
    {
        if ($subscription->currency->code !== 'USD') {
            return CardknoxResult::error(__('Cardknox only accepts payments in USD.'));
        }

        if ($this->vault->cardFor($subscription->tenant_id, $subscription->user_id) === null) {
            return CardknoxResult::error(__('No card on file.'));
        }

        // Read before anything changes: shouldSkipTrial() inspects the current type.
        $withTrial = $this->startsWithTrial($subscription);

        // Applied only once the first charge succeeds, so a declined
        // convert-local checkout leaves the local subscription untouched.
        $activate = [
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'quantity' => $quantity,
            'payment_provider_id' => $this->ledger->provider()->id,
            'payment_provider_subscription_id' => 'ckx_sub_'.$subscription->uuid,
        ];

        if ($withTrial) {
            $trialEndsAt = now()->addDays($this->subscriptionService->calculateSubscriptionTrialDays($subscription->plan));

            $this->subscriptionService->updateSubscription($subscription, $activate + [
                'status' => SubscriptionStatus::ACTIVE->value,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => $trialEndsAt,
            ]);

            return CardknoxResult::approvedWithoutCharge();
        }

        $first = $this->firstCharge($subscription, $quantity, $discount);

        $charge = $this->ledger->open(
            'S'.$subscription->id.'-F',
            CardknoxChargePurpose::First,
            $subscription,
            $first['amount'],
            $first['discount'],
            meta: ['activate' => $activate, 'discount_ids' => $discount === null ? [] : [$discount->id]],
        );

        if ($charge === null) {
            return CardknoxResult::inFlight();
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        return $this->ledger->execute($charge, (string) $card?->card_token, merchantInitiated: false);
    }

    public function payOrder(Order $order, string $token): CardknoxResult
    {
        if ($order->currency->code !== 'USD') {
            return CardknoxResult::error(__('Cardknox only accepts payments in USD.'));
        }

        $charge = $this->ledger->open(
            'O'.$order->id,
            CardknoxChargePurpose::Order,
            $order,
            (int) $order->total_amount_after_discount,
            (int) $order->total_discount_amount,
        );

        if ($charge === null) {
            return CardknoxResult::inFlight();
        }

        return $this->ledger->execute($charge, $token, merchantInitiated: false);
    }

    private function startsWithTrial(Subscription $subscription): bool
    {
        return (bool) $subscription->plan->has_trial && ! $this->subscriptionService->shouldSkipTrial($subscription);
    }

    /** @return array{amount: int, discount: int} */
    private function firstCharge(Subscription $subscription, int $quantity, ?Discount $discount): array
    {
        $plan = $subscription->plan;
        $subtotal = $this->pricing->recurringSubtotal($plan, $subscription->currency_id, $quantity);
        $discountAmount = $discount === null ? 0 : $this->pricing->discountFor($discount->type, $discount->amount, $subtotal);
        $setupFee = (int) ($this->pricing->planPrice($plan, $subscription->currency_id)->setup_fee ?? 0);

        return ['amount' => $subtotal - $discountAmount + $setupFee, 'discount' => $discountAmount];
    }
}
