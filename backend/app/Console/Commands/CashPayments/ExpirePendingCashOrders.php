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
 *  2. a cash subscription past ends_at goes PAST_DUE;
 *  3. a PAST_DUE cash subscription more than one TTL past ends_at is cancelled,
 *     along with any renewal order still waiting on it.
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

        foreach ($orders as $order) {
            $this->approvalService->reject(
                $order,
                OrderApprovalActor::SYSTEM,
                null,
                __('Cash payment was not confirmed within :hours hours.', ['hours' => $ttlHours]),
            );
        }

        return $orders->count();
    }

    private function markLapsedSubscriptionsPastDue(): int
    {
        $subscriptions = $this->cashSubscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
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
            ->where('status', SubscriptionStatus::PAST_DUE->value)
            ->where('ends_at', '<', now()->subHours($ttlHours))
            ->get();

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

            $this->subscriptionService->updateSubscription($subscription->fresh(), [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
            ]);
        }

        return $subscriptions->count();
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
