<?php

namespace App\Services\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\SubscriptionStatus;
use App\Models\Interval;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every cash decision — partner, admin, or the expiry sweep — goes through here
 * so that exactly one audit row exists per order and a duplicate click can
 * never double-activate a subscription (spec §7.2).
 */
class OrderApprovalService
{
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function isPendingCashOrder(Order $order): bool
    {
        return $order->status === OrderStatus::PENDING->value
            && (bool) $order->is_local
            && $this->amountDue($order) > 0;
    }

    /**
     * total_amount_after_discount is NOT NULL DEFAULT 0, so a null-coalesce
     * would read 0 for every order whose totals were never recalculated. Fall
     * back to total_amount when the discounted total is zero: a genuinely
     * fully-discounted order never reaches PENDING in the first place (a local
     * order with nothing owed is created SUCCESS — see OrderService::create).
     */
    public function amountDue(Order $order): int
    {
        $afterDiscount = (int) ($order->total_amount_after_discount ?? 0);

        return $afterDiscount > 0 ? $afterDiscount : (int) ($order->total_amount ?? 0);
    }

    public function approve(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::APPROVED, $note);

            /** @var Subscription|null $subscription */
            $subscription = $locked->subscription;

            if ($subscription !== null) {
                $this->extendSubscription($subscription);
            }

            $this->orderService->updateOrder($locked, ['status' => OrderStatus::SUCCESS->value]);

            return true;
        });
    }

    public function reject(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::REJECTED, $note);

            /** @var Subscription|null $subscription */
            $subscription = $locked->subscription;

            if ($subscription !== null) {
                if ($locked->type === OrderType::RENEWAL->value) {
                    // The cycle the customer already paid for is theirs; the
                    // expiry sweep cancels the subscription once ends_at passes.
                    $this->subscriptionService->updateSubscription($subscription, [
                        'is_canceled_at_end_of_cycle' => true,
                    ]);
                } else {
                    $this->subscriptionService->updateSubscription($subscription, [
                        'status' => SubscriptionStatus::CANCELED->value,
                        'cancelled_at' => now(),
                    ]);
                }
            }

            $this->orderService->updateOrder($locked, ['status' => OrderStatus::REJECTED->value]);

            return true;
        });
    }

    /**
     * Row lock plus a status re-read inside the transaction: two concurrent
     * approvals serialise here, and the loser sees a non-PENDING row.
     */
    private function lockIfPending(Order $order): ?Order
    {
        /** @var Order|null $locked */
        $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

        if ($locked === null || $locked->status !== OrderStatus::PENDING->value) {
            return null;
        }

        return $locked;
    }

    private function recordDecision(Order $order, OrderApprovalActor $actor, ?User $actingUser, OrderApprovalDecision $decision, ?string $note): void
    {
        OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => $actor->value,
            'actor_user_id' => $actor === OrderApprovalActor::SYSTEM ? null : $actingUser?->id,
            'decision' => $decision->value,
            'note' => $note,
            'decided_at' => now(),
        ]);
    }

    /**
     * Starts the new cycle at the current ends_at when that is still in the
     * future (an approved renewal), otherwise at now (a first purchase, or a
     * renewal approved after a lapse).
     */
    private function extendSubscription(Subscription $subscription): void
    {
        $currentEnd = $subscription->ends_at !== null ? Carbon::parse($subscription->ends_at) : null;
        $start = ($currentEnd !== null && $currentEnd->isFuture()) ? $currentEnd : now();

        /** @var Interval $interval */
        $interval = $subscription->interval;

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $start->copy()->add(
                $interval->date_identifier,
                (int) $subscription->interval_count,
            ),
        ]);
    }
}
