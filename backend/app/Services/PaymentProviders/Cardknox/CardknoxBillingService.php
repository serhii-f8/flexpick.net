<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\CardknoxCharge;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        private CardknoxClient $client,
    ) {}

    public function quoteFirstCharge(Subscription $subscription, int $quantity, ?Discount $discount): int
    {
        return $this->startsWithTrial($subscription)
            ? $this->setupFee($subscription)
            : $this->firstCharge($subscription, $quantity, $discount)['amount'];
    }

    /** The card must already be remembered in the vault. */
    public function startSubscription(Subscription $subscription, int $quantity, ?Discount $discount): CardknoxResult
    {
        if (Currency::query()->findOrFail($subscription->currency_id)->code !== 'USD') {
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

            $trial = [
                'status' => SubscriptionStatus::ACTIVE->value,
                'trial_ends_at' => $trialEndsAt->toDateTimeString(),
                'ends_at' => $trialEndsAt->toDateTimeString(),
            ];
            $setupFee = $this->setupFee($subscription);

            if ($setupFee <= 0) {
                $this->subscriptionService->updateSubscription($subscription, $activate + $trial);

                return CardknoxResult::approvedWithoutCharge();
            }

            // A trial waives the first period, not the one-off setup fee.
            $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, $setupFee, meta: ['activate' => $activate + $trial, 'discount_ids' => []]);

            return $charge === null
                ? CardknoxResult::inFlight()
                : $this->ledger->execute($charge, (string) $this->vault->cardFor($subscription->tenant_id, $subscription->user_id)?->card_token, merchantInitiated: false);
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
        if (Currency::query()->findOrFail($order->currency_id)->code !== 'USD') {
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

    /**
     * Phase 1 (locked, committed): decide and open the pending charge.
     * Phase 2 (no transaction): call the gateway and settle. Splitting them
     * means a crash after the gateway approves still leaves a committed
     * pending transaction for reconciliation to settle.
     */
    public function chargeRenewal(Subscription $subscription, bool $force = false): ?CardknoxResult
    {
        $charge = DB::transaction(function () use ($subscription, $force) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if (! $this->isDue($locked, $force)) {
                return null;
            }

            if ($locked->is_canceled_at_end_of_cycle) {
                $this->subscriptionService->updateSubscription($locked, [
                    'status' => SubscriptionStatus::CANCELED->value,
                    'cancelled_at' => now(),
                ]);

                return null;
            }

            $periodEnd = Carbon::parse($locked->ends_at);
            $amount = $this->pricing->renewalAmount($locked, $this->pricing->subInterval($locked, $periodEnd), $periodEnd);

            return $this->ledger->open(
                'S'.$locked->id.'-'.$periodEnd->format('Ymd'),
                CardknoxChargePurpose::Renewal,
                $locked,
                $amount->total,
                $amount->discount,
                $periodEnd,
                ['credit' => $amount->credit, 'discount_ids' => $amount->discountIdsUsed],
            );
        });

        if ($charge === null) {
            return null;
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        if ($card === null) {
            $result = CardknoxResult::error(__('No card on file.'));
            $this->ledger->settle($charge, $result);

            return $result;
        }

        return $this->ledger->execute($charge, $card->card_token, merchantInitiated: true);
    }

    /** Used after the customer updates their card: retry an overdue renewal now. */
    public function retryOutstanding(Subscription $subscription): ?CardknoxResult
    {
        if ($subscription->status !== SubscriptionStatus::PAST_DUE->value) {
            return null;
        }

        return $this->chargeRenewal($subscription, force: true);
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $prorate): bool
    {
        // Seat plans bill the tenant's member count, as CalculationService::calculateNewPlanTotals() does.
        $quantity = $newPlan->type === PlanType::SEAT_BASED->value
            ? max(1, Tenant::query()->findOrFail($subscription->tenant_id)->users()->count())
            : max(1, (int) $subscription->quantity);

        $newPrice = $this->pricing->planPrice($newPlan, $subscription->currency_id);
        $oldSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $newSubtotal = $this->pricing->recurringSubtotal($newPlan, $subscription->currency_id, $quantity);

        return $this->applyChange($subscription, $oldSubtotal, $newSubtotal, $prorate, [
            'plan_id' => $newPlan->id,
            'price' => $newPrice->price,
            'quantity' => $quantity,
            'interval_id' => $newPlan->interval_id,
            'interval_count' => $newPlan->interval_count,
            'price_type' => $newPrice->type,
            'price_tiers' => $newPrice->tiers,
            'price_per_unit' => $newPrice->price_per_unit,
        ]);
    }

    public function changeQuantity(Subscription $subscription, int $quantity, bool $prorate): bool
    {
        $oldSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $newSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, $quantity);

        return $this->applyChange($subscription, $oldSubtotal, $newSubtotal, $prorate, ['quantity' => $quantity]);
    }

    public function refund(Transaction $transaction): CardknoxResult
    {
        $charge = CardknoxCharge::query()->where('transaction_id', $transaction->id)->firstOrFail();

        if ($charge->ref_num === null) {
            return CardknoxResult::error(__('This charge has no gateway reference to refund.'));
        }

        $result = $this->client->refund($charge->ref_num);

        // An unsettled charge cannot be refunded, only voided. Never void after
        // an Unknown refund: the refund may have gone through.
        if (in_array($result->outcome, [CardknoxOutcome::Declined, CardknoxOutcome::Error], true)) {
            $result = $this->client->void($charge->ref_num);
        }

        if ($result->isApproved()) {
            $this->ledger->markRefunded($charge);
        }

        return $result;
    }

    private function isDue(Subscription $subscription, bool $force): bool
    {
        if ($subscription->type !== SubscriptionType::PAYMENT_PROVIDER_MANAGED
            || $subscription->ends_at === null
            || $subscription->payment_provider_id !== $this->ledger->provider()->id
            || Carbon::parse($subscription->ends_at)->isFuture()) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE->value) {
            return true;
        }

        if ($subscription->status !== SubscriptionStatus::PAST_DUE->value) {
            return false;
        }

        $nextRetryAt = $subscription->extra_payment_provider_data['next_retry_at'] ?? null;

        return $force || $nextRetryAt === null || Carbon::parse($nextRetryAt)->lte(now());
    }

    /**
     * Upgrade: charge the prorated difference now; the change is applied by
     * the ledger only when that charge settles approved. Downgrade: apply now
     * and bank the difference as credit for the next renewal (no card refund).
     */
    private function applyChange(Subscription $subscription, int $oldSubtotal, int $newSubtotal, bool $prorate, array $change): bool
    {
        $onTrial = $subscription->trial_ends_at !== null && Carbon::parse($subscription->trial_ends_at)->isFuture();

        if (! $prorate || $onTrial) {
            $this->subscriptionService->updateSubscription($subscription, $change);

            return true;
        }

        $delta = $this->pricing->prorationAmount($subscription, $oldSubtotal, $newSubtotal, now());

        if ($delta <= 0) {
            $credit = (int) ($subscription->extra_payment_provider_data['pending_credit'] ?? 0) + abs($delta);

            $this->subscriptionService->updateSubscription($subscription, $change + [
                'extra_payment_provider_data' => CardknoxChargeLedger::mergeData($subscription, ['pending_credit' => $credit]),
            ]);

            return true;
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        if ($card === null) {
            return false;
        }

        // Numbered by settled changes, not by clock: a resubmit while an attempt
        // is pending or approved reuses the base and is refused by open().
        $settledChanges = CardknoxCharge::query()
            ->where('purpose', CardknoxChargePurpose::Proration->value)
            ->whereHas('transaction', fn ($query) => $query
                ->where('subscription_id', $subscription->id)
                ->where('status', TransactionStatus::SUCCESS->value))
            ->count();

        $charge = $this->ledger->open(
            'S'.$subscription->id.'-P'.($settledChanges + 1),
            CardknoxChargePurpose::Proration,
            $subscription,
            $delta,
            meta: ['change' => $change],
        );

        if ($charge === null) {
            return false;
        }

        return $this->ledger->execute($charge, $card->card_token, merchantInitiated: false)->isApproved();
    }

    private function setupFee(Subscription $subscription): int
    {
        return (int) ($this->pricing->planPrice(Plan::query()->findOrFail($subscription->plan_id), $subscription->currency_id)->setup_fee ?? 0);
    }

    private function startsWithTrial(Subscription $subscription): bool
    {
        return (bool) Plan::query()->findOrFail($subscription->plan_id)->has_trial && ! $this->subscriptionService->shouldSkipTrial($subscription);
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
