<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Models\CardknoxCharge;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\OrderService;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of one gateway charge: open a pending transaction under
 * a unique invoice key (committed before any HTTP call, so a crash after the
 * gateway approves still leaves a record to reconcile), send it, settle it.
 *
 * settle() is the only place a gateway result changes local state. The
 * synchronous path, the webhook job and reconciliation all call it, in any
 * order and any number of times.
 */
class CardknoxChargeLedger
{
    public function __construct(
        private CardknoxClient $client,
        private CardknoxPricing $pricing,
        private SubscriptionService $subscriptionService,
        private TransactionService $transactionService,
        private OrderService $orderService,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();
    }

    public function open(
        string $base,
        CardknoxChargePurpose $purpose,
        Subscription|Order $payable,
        int $amount,
        int $discount = 0,
        ?Carbon $periodEnd = null,
        array $meta = [],
    ): ?CardknoxCharge {
        $previous = CardknoxCharge::query()->where('invoice_base', $base)->with('transaction')->get();

        $blocked = $previous->contains(fn (CardknoxCharge $charge): bool => in_array(
            $charge->transaction?->status,
            [TransactionStatus::PENDING->value, TransactionStatus::SUCCESS->value],
            true,
        ));

        if ($blocked) {
            return null;
        }

        $invoice = $base.'-'.($previous->count() + 1);
        $provider = $this->provider();

        try {
            return DB::transaction(function () use ($invoice, $base, $purpose, $payable, $amount, $discount, $periodEnd, $meta, $provider): CardknoxCharge {
                $transaction = $payable instanceof Order
                    ? $this->transactionService->createForOrder($payable, $amount, 0, $discount, 0, $payable->currency, $provider, $invoice, 'pending', TransactionStatus::PENDING)
                    : $this->transactionService->createForSubscription($payable, $amount, 0, $discount, 0, $payable->currency, $provider, $invoice, 'pending', TransactionStatus::PENDING);

                return CardknoxCharge::create([
                    'transaction_id' => $transaction->id,
                    'invoice_base' => $base,
                    'invoice' => $invoice,
                    'purpose' => $purpose->value,
                    'period_end' => $periodEnd,
                    'meta' => $meta,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent run opened this exact attempt first.
            return null;
        }
    }

    public function execute(CardknoxCharge $charge, string $token, bool $merchantInitiated): CardknoxResult
    {
        $amount = (int) $charge->transaction->amount;

        $result = $amount <= 0
            ? CardknoxResult::approvedWithoutCharge()
            : $this->client->sale($token, $amount, $charge->invoice, $merchantInitiated ? CardknoxClient::RECURRING : null);

        $this->settle($charge, $result);

        return $result;
    }

    public function settle(CardknoxCharge $charge, CardknoxResult $result): void
    {
        if ($result->outcome === CardknoxOutcome::Unknown) {
            return;
        }

        DB::transaction(function () use ($charge, $result): void {
            $transaction = Transaction::query()->whereKey($charge->transaction_id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== TransactionStatus::PENDING->value) {
                return;
            }

            if ($result->refNum !== null) {
                $charge->update(['ref_num' => $result->refNum]);
            }

            if ($result->isApproved()) {
                $this->transactionService->updateTransaction($transaction, 'approved', TransactionStatus::SUCCESS);
                $this->applyApproved($charge, $transaction);

                return;
            }

            $this->transactionService->updateTransaction(
                $transaction,
                $result->outcome->value,
                TransactionStatus::FAILED,
                $result->error ?? __('Payment declined'),
            );
            $this->applyFailed($charge, $transaction);
        });
    }

    public function markRefunded(CardknoxCharge $charge): void
    {
        DB::transaction(function () use ($charge): void {
            $transaction = Transaction::query()->whereKey($charge->transaction_id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== TransactionStatus::SUCCESS->value) {
                return;
            }

            $this->transactionService->updateTransaction($transaction, 'refunded', TransactionStatus::REFUNDED);

            if ($transaction->order_id !== null) {
                $this->orderService->updateOrder(Order::query()->findOrFail($transaction->order_id), [
                    'status' => OrderStatus::REFUNDED->value,
                ]);
            }
        });
    }

    public function markDisputed(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::DISPUTED->value) {
                return;
            }

            $this->transactionService->updateTransaction($locked, 'disputed', TransactionStatus::DISPUTED);

            if ($locked->order_id !== null) {
                $this->orderService->updateOrder(Order::query()->findOrFail($locked->order_id), [
                    'status' => OrderStatus::DISPUTED->value,
                ]);
            }
        });
    }

    public static function mergeData(Subscription $subscription, array $values): array
    {
        return array_merge($subscription->extra_payment_provider_data ?? [], $values);
    }

    private function applyApproved(CardknoxCharge $charge, Transaction $transaction): void
    {
        $purpose = CardknoxChargePurpose::from($charge->purpose);

        if ($purpose === CardknoxChargePurpose::Order) {
            $this->orderService->updateOrder(Order::query()->findOrFail($transaction->order_id), [
                'status' => OrderStatus::SUCCESS->value,
                'payment_provider_id' => $transaction->payment_provider_id,
                'payment_provider_order_id' => $charge->invoice,
            ]);

            return;
        }

        $subscription = Subscription::query()->whereKey($transaction->subscription_id)->lockForUpdate()->firstOrFail();
        $meta = $charge->meta ?? [];

        if ($purpose === CardknoxChargePurpose::First) {
            $this->subscriptionService->updateSubscription($subscription, array_merge((array) ($meta['activate'] ?? []), [
                'status' => SubscriptionStatus::ACTIVE->value,
                'ends_at' => $this->pricing->addInterval($subscription, Carbon::parse($charge->created_at)),
                'extra_payment_provider_data' => self::mergeData($subscription, [
                    'discount_uses' => self::countUses([], (array) ($meta['discount_ids'] ?? [])),
                ]),
            ]));
        } elseif ($purpose === CardknoxChargePurpose::Renewal) {
            $this->applyRenewal($subscription, $charge);
        } elseif ($purpose === CardknoxChargePurpose::Proration) {
            $this->subscriptionService->updateSubscription($subscription, (array) ($meta['change'] ?? []));
        }
    }

    private function applyRenewal(Subscription $subscription, CardknoxCharge $charge): void
    {
        $periodEnd = Carbon::parse($charge->period_end);

        // A late settle (webhook, reconciliation) must never advance a period twice.
        if (! Carbon::parse($subscription->ends_at)->equalTo($periodEnd)) {
            return;
        }

        $data = $subscription->extra_payment_provider_data ?? [];
        $meta = $charge->meta ?? [];

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $this->pricing->addInterval($subscription, $periodEnd),
            'extra_payment_provider_data' => self::mergeData($subscription, [
                'failed_attempts' => 0,
                'next_retry_at' => null,
                'pending_credit' => max(0, (int) ($data['pending_credit'] ?? 0) - (int) ($meta['credit'] ?? 0)),
                'discount_uses' => self::countUses((array) ($data['discount_uses'] ?? []), (array) ($meta['discount_ids'] ?? [])),
            ]),
        ]);
    }

    private function applyFailed(CardknoxCharge $charge, Transaction $transaction): void
    {
        // First, proration and order failures leave the customer free to retry
        // on the pay page; only renewals walk the retry ladder.
        if ($charge->purpose !== CardknoxChargePurpose::Renewal->value) {
            return;
        }

        $subscription = Subscription::query()->whereKey($transaction->subscription_id)->lockForUpdate()->firstOrFail();
        $failedAttempts = (int) ($subscription->extra_payment_provider_data['failed_attempts'] ?? 0) + 1;
        $retryDays = array_values((array) config('services.cardknox.retry_days'));

        $this->subscriptionService->handleInvoicePaymentFailed($subscription);

        if ($failedAttempts > count($retryDays)) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
                'extra_payment_provider_data' => self::mergeData($subscription, [
                    'failed_attempts' => $failedAttempts,
                    'next_retry_at' => null,
                ]),
            ]);

            return;
        }

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'extra_payment_provider_data' => self::mergeData($subscription, [
                'failed_attempts' => $failedAttempts,
                'next_retry_at' => Carbon::parse($charge->period_end)->addDays((int) $retryDays[$failedAttempts - 1])->toDateTimeString(),
            ]),
        ]);
    }

    /** @param  list<int|string>  $discountIds */
    private static function countUses(array $uses, array $discountIds): array
    {
        foreach ($discountIds as $id) {
            $uses[$id] = (int) ($uses[$id] ?? 0) + 1;
        }

        return $uses;
    }
}
