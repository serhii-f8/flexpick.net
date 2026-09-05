<?php

namespace App\Services\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Mail\CashPayments\CustomerOrderApproved;
use App\Mail\CashPayments\CustomerOrderExpired;
use App\Mail\CashPayments\CustomerOrderRejected;
use App\Models\Interval;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\RenderSafeMailer;
use App\Services\OrderService;
use App\Services\PartnerCapabilityService;
use App\Services\SubscriptionService;
use App\Services\TenantPermissionService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
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
        private PartnerCapabilityService $capabilityService,
        private TenantPermissionService $permissionService,
        private RenderSafeMailer $mailer,
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
        $changed = DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::APPROVED, $note);

            $subscription = $this->lockSubscriptionForOrder($locked);

            if ($subscription !== null) {
                $this->extendSubscription($subscription);
            }

            $this->orderService->updateOrder($locked, ['status' => OrderStatus::SUCCESS->value]);

            return true;
        });

        if ($changed) {
            $this->notifyCustomer($order->fresh(), OrderApprovalDecision::APPROVED, $actor);
        }

        return $changed;
    }

    public function reject(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        $changed = DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::REJECTED, $note);

            $subscription = $this->lockSubscriptionForOrder($locked);

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

        if ($changed) {
            $this->notifyCustomer($order->fresh(), OrderApprovalDecision::REJECTED, $actor);
        }

        return $changed;
    }

    /**
     * Row lock plus a re-read inside the transaction: two concurrent
     * approvals serialise here, and the loser sees a row that no longer
     * qualifies. Gating on isPendingCashOrder() (not the bare status) keeps
     * this service inside its own domain — a gateway order that happens to be
     * PENDING is not this service's to complete or reject.
     */
    private function lockIfPending(Order $order): ?Order
    {
        /** @var Order|null $locked */
        $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $this->isPendingCashOrder($locked)) {
            return null;
        }

        return $locked;
    }

    /**
     * Locks the order's subscription (if any) inside the same transaction as
     * the order row. The lock order is always order -> subscription, which is
     * the only order this service takes, so it cannot deadlock against
     * itself. Without this, two different PENDING orders on the same
     * subscription (e.g. two renewals approved back to back) would each read
     * the same ends_at and the second write would silently overwrite the
     * first, losing a paid cycle.
     */
    private function lockSubscriptionForOrder(Order $order): ?Subscription
    {
        if ($order->subscription_id === null) {
            return null;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::whereKey($order->subscription_id)->lockForUpdate()->first();

        return $subscription;
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
            // A prior rejected renewal may have left this sticky (reject()
            // sets it without touching ends_at, on purpose, to let the paid
            // cycle run out). Paying for a fresh cycle now supersedes that.
            'is_canceled_at_end_of_cycle' => false,
            'ends_at' => $start->copy()->add(
                $interval->date_identifier,
                (int) $subscription->interval_count,
            ),
        ]);
    }

    private function notifyCustomer(Order $order, OrderApprovalDecision $decision, OrderApprovalActor $actor): void
    {
        /** @var User|null $customer */
        $customer = $order->user;
        $email = $customer?->email;

        if ($email === null) {
            return;
        }

        $mailable = match (true) {
            $decision === OrderApprovalDecision::APPROVED => new CustomerOrderApproved($order),
            $actor === OrderApprovalActor::SYSTEM => new CustomerOrderExpired($order),
            default => new CustomerOrderRejected($order),
        };

        $this->mailer->send($mailable, $email);
    }

    public function approveAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool
    {
        $this->assertPartnerMayAct($order, $user, $actingTenant);

        return $this->approve($order, OrderApprovalActor::PARTNER, $user, $note);
    }

    public function rejectAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool
    {
        $this->assertPartnerMayAct($order, $user, $actingTenant);

        return $this->reject($order, OrderApprovalActor::PARTNER, $user, $note);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertPartnerMayAct(Order $order, User $user, Tenant $actingTenant): void
    {
        if ($order->partner_tenant_id === null || (int) $order->partner_tenant_id !== (int) $actingTenant->getKey()) {
            throw new AuthorizationException(__('This order does not belong to your partner account.'));
        }

        // Re-checked at action time, not only at page load: a Partner Plan can
        // lapse between rendering the queue and clicking Approve (spec §7.5).
        if (! $this->capabilityService->tenantIsActivePartner($actingTenant)) {
            throw new AuthorizationException(__('Your partner plan is not active.'));
        }

        if (! $this->permissionService->tenantUserHasPermissionTo(
            $actingTenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
        )) {
            throw new AuthorizationException(__('You do not have permission to approve partner orders.'));
        }
    }
}
