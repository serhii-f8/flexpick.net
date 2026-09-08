<?php

namespace App\Services;

use App\Constants\OrderStatus;
use App\Constants\PlanType;
use App\Dto\CartDto;
use App\Dto\TotalsDto;
use App\Exceptions\PurchaseNotAllowedException;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashPayments\PurchaseSnapshotService;

class CheckoutService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private OrderService $orderService,
        private TenantCreationService $tenantCreationService,
        private PlanService $planService,
        private OneTimeProductService $oneTimeProductService,
        private PurchaseSnapshotService $purchaseSnapshotService,
        private PartnerPricingResolver $partnerPricingResolver,
    ) {}

    public function initSubscriptionCheckout(string $planSlug, ?string $tenantUuid, int $quantity = 1, bool $shouldCreateNewTenant = false)
    {
        $plan = $this->planService->getActivePlanBySlug($planSlug);
        $this->assertPlanPurchasable($plan);
        $tenant = $this->resolveSubscriptionTenant($shouldCreateNewTenant, $tenantUuid, $plan);

        $subscription = $this->subscriptionService->findNewByPlanSlugAndTenant($planSlug, $tenant);
        if ($subscription === null) {
            $subscription = $this->subscriptionService->create(
                planSlug: $planSlug,
                userId: auth()->id(),
                quantity: $quantity,
                tenant: $tenant,
            );
        } elseif (($buyer = auth()->user()) !== null) {
            // A reused NEW subscription can predate this buyer's partner
            // attribution — or the partner's offering — so its frozen price
            // and snapshot are re-derived here rather than trusted. Price is
            // never carried forward from an earlier request (Decision 9).
            $subscription = $this->subscriptionService->syncPlanPurchaseForBuyer($subscription, $buyer);
        }

        $plan = $subscription->plan;

        if ($plan->type === PlanType::SEAT_BASED->value) { // in case tenant already had users inside
            $subscription->update(['quantity' => max($tenant->users->count(), $quantity)]);
        }

        return $subscription;
    }

    public function initLocalSubscriptionCheckout(string $planSlug, ?string $tenantUuid, int $quantity = 1, bool $shouldCreateNewTenant = false)
    {
        $plan = $this->planService->getActivePlanBySlug($planSlug);
        $this->assertPlanPurchasable($plan);
        $tenant = $this->resolveSubscriptionTenant($shouldCreateNewTenant, $tenantUuid, $plan);

        $subscription = $this->subscriptionService->findNewByPlanSlugAndTenant($planSlug, $tenant);
        if ($subscription === null) {
            $subscription = $this->subscriptionService->create(
                $planSlug,
                auth()->id(),
                quantity: $quantity,
                tenant: $tenant,
                localSubscription: true);
        } elseif (($buyer = auth()->user()) !== null) {
            // Same reasoning as initSubscriptionCheckout() above, with the
            // local rule applied: an abandoned paid checkout can have frozen
            // the partner price on this very row, and the trial flow converts
            // through a gateway, so it is repriced to base rather than trusted.
            $subscription = $this->subscriptionService->syncPlanPurchaseForBuyer($subscription, $buyer, localSubscription: true);
        }

        $plan = $subscription->plan;

        if ($plan->type === PlanType::SEAT_BASED->value) { // in case tenant already had users inside
            $subscription->update(['quantity' => max($tenant->users->count(), $quantity)]);
        }

        return $subscription;
    }

    public function initProductCheckout(CartDto $cartDto, ?string $tenantUuid, TotalsDto $totalsDto, bool $shouldCreateNewTenant = false)
    {
        $user = auth()->user();

        $firstItem = $cartDto->items[0] ?? null;
        if ($firstItem !== null) {
            $this->assertProductPurchasable($this->oneTimeProductService->getOneTimeProductById($firstItem->productId));
        }

        $isLocalOrder = $totalsDto->amountDue === 0; // If amount due is zero, it's a local order (no payment provider needed)

        if ($shouldCreateNewTenant) {
            $tenant = $this->tenantCreationService->createTenant($user);
        } else {
            $tenant = $this->tenantCreationService->findUserTenantForNewOrderByUuid($user, $tenantUuid);

            if ($tenant === null) {
                // just find any tenant user can use
                $tenant = $this->tenantCreationService->findUserTenantForNewOrder($user);

                if ($tenant === null) {
                    $tenant = $this->tenantCreationService->createTenant($user);
                }
            }
        }

        $order = null;
        if ($cartDto->orderId !== null) {
            $order = $this->orderService->findNewByIdForUser($cartDto->orderId, $user);
        }

        if ($order === null) {
            $order = $this->orderService->create(
                $user,
                $tenant,
                isLocal: $isLocalOrder,
                snapshot: $this->snapshotForCart($cartDto, $user),
            );
        }

        $this->orderService->refreshOrder($cartDto, $order);

        if (! $isLocalOrder) {
            $order->status = OrderStatus::PENDING->value;
            $order->save();
        }

        return $order;
    }

    public function resolveSubscriptionTenant(bool $shouldCreateNewTenant, ?string $tenantUuid, ?Plan $plan = null): Tenant
    {
        if ($shouldCreateNewTenant) {
            $tenant = $this->tenantCreationService->createTenant(auth()->user());
        } else {
            $tenant = $this->tenantCreationService->findUserTenantForNewSubscriptionByUuid(auth()->user(), $tenantUuid, $plan);

            if ($tenant === null) {
                // just find any tenant user can use
                $tenant = $this->tenantCreationService->findUserTenantForNewSubscription(auth()->user());

                if ($tenant === null) {
                    // No eligible tenant -- but rather than always spinning
                    // up a new workspace, check whether the block is one we
                    // can clear ourselves: a tenant whose only live
                    // subscription(s) are cash-collected. A repeat cash
                    // purchase supersedes it in place instead of stranding
                    // the buyer's new order on a workspace they never see.
                    $tenant = $this->tenantCreationService->findUserTenantWithSupersedableCashSubscription(auth()->user());

                    if ($tenant !== null) {
                        $this->subscriptionService->supersedeCashSubscriptions($tenant);
                    }
                }

                if ($tenant === null) {
                    $tenant = $this->tenantCreationService->createTenant(auth()->user());
                }
            }
        }

        return $tenant;
    }

    /**
     * Product checkout is single-product in this codebase (every caller reads
     * $cartDto->items[0]), so the snapshot follows the first cart item.
     *
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}|array{}
     */
    private function snapshotForCart(CartDto $cartDto, User $user): array
    {
        $firstItem = $cartDto->items[0] ?? null;

        if ($firstItem === null) {
            return [];
        }

        return $this->purchaseSnapshotService->forProduct(
            $user,
            $this->oneTimeProductService->getOneTimeProductById($firstItem->productId),
        );
    }

    /**
     * @throws PurchaseNotAllowedException
     */
    private function assertPlanPurchasable(Plan $plan): void
    {
        if ($this->partnerPricingResolver->purchasablePlans(collect([$plan]), auth()->user())->isEmpty()) {
            throw new PurchaseNotAllowedException("Plan [{$plan->slug}] is not available for this buyer.");
        }
    }

    /**
     * @throws PurchaseNotAllowedException
     */
    private function assertProductPurchasable(OneTimeProduct $product): void
    {
        // A product no partner can ever configure an offering for — the only
        // path that creates a PartnerProductOffering, PartnerProductPricingTable's
        // table query, lists only is_visible ones — was never this feature's
        // target. Gating a purely transactional SKU like audit-report-unlock
        // would permanently strand every attributed buyer, since no offering
        // for it can ever exist to satisfy the check below.
        if (! $product->is_visible) {
            return;
        }

        if ($this->partnerPricingResolver->purchasableProducts(collect([$product]), auth()->user())->isEmpty()) {
            throw new PurchaseNotAllowedException("Product [{$product->slug}] is not available for this buyer.");
        }
    }
}
