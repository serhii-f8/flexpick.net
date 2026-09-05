<?php

namespace App\Services\CashPayments;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerCatalogService;
use App\Services\PartnerPricingResolver;

/**
 * Decides what an order/subscription freezes at creation time (spec §6.2).
 *
 * The snapshot is written for every purchase, partner-attributed or not, so a
 * later admin edit to a product's metadata can never retroactively change an
 * existing customer's entitlement.
 */
class PurchaseSnapshotService
{
    public function __construct(
        private PartnerCatalogService $catalogService,
        private PartnerPricingResolver $pricingResolver,
    ) {}

    /**
     * The partner tenant this buyer is attributed to, but only while that
     * tenant's Partner Plan is currently active (spec §7.5).
     *
     * Delegates so the write side can never disagree with the storefront about
     * who the partner is.
     */
    public function partnerTenantFor(User $user): ?Tenant
    {
        return $this->pricingResolver->resolvePartnerTenant($user);
    }

    /**
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}
     */
    public function forPlan(User $user, Plan $plan): array
    {
        /** @var Product|null $product */
        $product = $plan->product;
        $baseMetadata = (array) ($product->metadata ?? []);
        $offering = $this->pricingResolver->usablePlanOffering($user, $plan);

        return [
            'partner_tenant_id' => $offering?->tenant_id,
            'base_price_snapshot' => $this->basePriceOrNull(fn (): int => $this->catalogService->planBasePrice($plan)),
            'quota_snapshot' => (array) ($offering->quota_overrides ?? []) + $baseMetadata,
        ];
    }

    /**
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}
     */
    public function forProduct(User $user, OneTimeProduct $product): array
    {
        $baseMetadata = (array) ($product->metadata ?? []);
        $offering = $this->pricingResolver->usableProductOffering($user, $product);

        return [
            'partner_tenant_id' => $offering?->tenant_id,
            'base_price_snapshot' => $this->basePriceOrNull(fn (): int => $this->catalogService->productBasePrice($product)),
            'quota_snapshot' => (array) ($offering->quota_overrides ?? []) + $baseMetadata,
        ];
    }

    /**
     * Checkout must not blow up on an item that has no price row in the store's
     * default currency — the margin column simply has nothing to report.
     *
     * @param  callable(): int  $resolver
     */
    private function basePriceOrNull(callable $resolver): ?int
    {
        try {
            return $resolver();
        } catch (PartnerOfferingValidationException) {
            return null;
        }
    }
}
