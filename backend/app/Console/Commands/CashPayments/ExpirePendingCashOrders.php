<?php

namespace App\Console\Commands\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cash lifecycle's dead-man's switch (spec §6.5, §6.6):
 *
 *  1. a pending PURCHASE order older than the TTL is system-rejected;
 *  2. a cash subscription past ends_at goes PAST_DUE, unless it is already
 *     flagged is_canceled_at_end_of_cycle — the decision not to renew has
 *     already been made, so it gets no grace window (rule 3 instead);
 *  3. cancelled: either a PAST_DUE cash subscription more than one TTL past
 *     ends_at, or a cash subscription past ends_at that is flagged
 *     is_canceled_at_end_of_cycle — along with any renewal order still
 *     waiting on it.
 *
 * Renewal orders are aged off ends_at, not created_at: they are opened before
 * the cycle they renew and would otherwise expire before that cycle ends.
 */
class ExpirePendingCashOrders extends Command
{
    protected $signature = 'app:expire-pending-cash-orders';

    protected $description = 'Reject stale pending cash orders and retire the subscriptions behind them';

    public function __construct(
        private OrderApprovalService $approvalService,
        private SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ttlHours = (int) config('cash_payments.pending_ttl_hours');

        $expired = $this->expireStalePurchaseOrders($ttlHours);
        $pastDue = $this->markLapsedSubscriptionsPastDue();
        $cancelled = $this->cancelAbandonedSubscriptions($ttlHours);

        $this->info("Expired {$expired} purchase order(s), marked {$pastDue} subscription(s) past due, cancelled {$cancelled}.");

        return self::SUCCESS;
    }

    private function expireStalePurchaseOrders(int $ttlHours): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::PENDING->value)
            ->where('is_local', true)
            ->where('type', OrderType::PURCHASE->value)
            ->where('created_at', '<', now()->subHours($ttlHours))
            ->get();

        $rejected = 0;

        foreach ($orders as $order) {
            $rejected += (int) $this->approvalService->reject(
                $order,
                OrderApprovalActor::SYSTEM,
                null,
                __('Cash payment was not confirmed within :hours hours.', ['hours' => $ttlHours]),
            );
        }

        return $rejected;
    }

    private function markLapsedSubscriptionsPastDue(): int
    {
        $subscriptions = $this->cashSubscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            // A subscription already flagged not to renew gets no grace
            // window at all — rule 3 cancels it outright once ends_at passes.
            ->where('is_canceled_at_end_of_cycle', false)
            ->where('ends_at', '<', now())
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::PAST_DUE->value,
            ]);
        }

        return $subscriptions->count();
    }

    private function cancelAbandonedSubscriptions(int $ttlHours): int
    {
        $subscriptions = $this->cashSubscriptions()
            ->where('status', '!=', SubscriptionStatus::CANCELED->value)
            ->where(function (Builder $query) use ($ttlHours) {
                $query->where(function (Builder $q) use ($ttlHours) {
                    $q->where('status', SubscriptionStatus::PAST_DUE->value)
                        ->where('ends_at', '<', now()->subHours($ttlHours));
                })->orWhere(function (Builder $q) {
                    // Already decided not to renew: no grace window, cancel
                    // as soon as the paid-for cycle ends.
                    $q->where('is_canceled_at_end_of_cycle', true)
                        ->where('ends_at', '<', now());
                });
            })
            ->get();

        $cancelled = 0;

        foreach ($subscriptions as $subscription) {
            $pendingRenewals = $subscription->orders()
                ->where('type', OrderType::RENEWAL->value)
                ->where('status', OrderStatus::PENDING->value)
                ->get();

            foreach ($pendingRenewals as $renewal) {
                $this->approvalService->reject(
                    $renewal,
                    OrderApprovalActor::SYSTEM,
                    null,
                    __('Renewal was not confirmed within :hours hours of the cycle ending.', ['hours' => $ttlHours]),
                );
            }

            // A row hard-deleted between the query and here would otherwise
            // hand updateSubscription() a null and abandon every remaining
            // subscription in this loop.
            if (($fresh = $subscription->fresh()) === null) {
                continue;
            }

            $this->subscriptionService->updateSubscription($fresh, [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
            ]);

            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * @return Builder<Subscription>
     */
    private function cashSubscriptions(): Builder
    {
        return Subscription::query()
            ->where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('price', '>', 0)
            ->whereNotNull('ends_at')
            ->whereHas('paymentProvider', fn (Builder $query) => $query->where('slug', PaymentProviderConstants::OFFLINE_SLUG));
    }
}
