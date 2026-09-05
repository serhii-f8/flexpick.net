<?php

namespace App\Services\CashPayments;

use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Services\OrderService;
use App\Services\SubscriptionService;

/**
 * A cash subscription never activates on its own: every cycle it wants paid is
 * represented by a PENDING order that a partner or an admin has to approve
 * (spec §6.1, §6.6).
 */
class CashSubscriptionService
{
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function isCashSubscription(Subscription $subscription): bool
    {
        /** @var PaymentProvider|null $paymentProvider */
        $paymentProvider = $subscription->paymentProvider;

        return $subscription->type === SubscriptionType::LOCALLY_MANAGED
            && $paymentProvider?->slug === PaymentProviderConstants::OFFLINE_SLUG
            && (int) $subscription->price > 0;
    }

    public function startPendingCashSubscription(Subscription $subscription): Order
    {
        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::PENDING->value,
        ]);

        return $this->createPendingOrder($subscription, OrderType::PURCHASE);
    }

    /**
     * The order copies the subscription's own locked-in values — never the
     * partner's current catalog — so a partner editing their offering after the
     * sale cannot change what an existing subscriber owes or receives (§6.6).
     */
    public function createPendingOrder(Subscription $subscription, OrderType $type): Order
    {
        return $this->orderService->create(
            user: $subscription->user,
            tenant: $subscription->tenant,
            paymentProvider: $subscription->paymentProvider,
            totalAmount: (int) $subscription->price,
            currency: $subscription->currency,
            isLocal: true,
            snapshot: [
                'partner_tenant_id' => $subscription->partner_tenant_id,
                'base_price_snapshot' => $subscription->base_price_snapshot,
                'quota_snapshot' => $subscription->quota_snapshot,
                'subscription_id' => $subscription->id,
                'type' => $type->value,
            ],
        );
    }
}
