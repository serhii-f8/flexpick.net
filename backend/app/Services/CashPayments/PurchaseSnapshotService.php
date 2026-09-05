<?php

namespace App\Services\CashPayments;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerCapabilityService;
use App\Services\PartnerCatalogService;

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
        private PartnerCapabilityService $capabilityService,
        private PartnerCatalogService $catalogService,
    ) {}

    /**
     * The partner tenant this buyer is attributed to, but only while that
     * tenant's Partner Plan is currently active (spec §7.5).
     */
    public function partnerTenantFor(User $user): ?Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = $user->partnerTenant;

        if ($tenant === null) {
            return null;
        }

        return $this->capabilityService->tenantIsActivePartner($tenant) ? $tenant : null;
    }

    /**
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}
     */
    public function forPlan(User $user, Plan $plan): array
    {
        /** @var Product|null $product */
        $product = $plan->product;
        $baseMetadata = (array) ($product->metadata ?? []);
        $offering = $this->usablePlanOffering($user, $plan);

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
        $offering = $this->usableProductOffering($user, $product);

        return [
            'partner_tenant_id' => $offering?->tenant_id,
            'base_price_snapshot' => $this->basePriceOrNull(fn (): int => $this->catalogService->productBasePrice($product)),
            'quota_snapshot' => (array) ($offering->quota_overrides ?? []) + $baseMetadata,
        ];
    }

    /**
     * An offering only owns the sale when it is enabled AND still at or above
     * the live admin floor. A disabled or below-minimum offering makes this a
     * direct sale (base pricing, admin-approved) rather than blocking checkout.
     */
    private function usablePlanOffering(User $user, Plan $plan): ?PartnerPlanOffering
    {
        $tenant = $this->partnerTenantFor($user);

        if ($tenant === null) {
            return null;
        }

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isPlanOfferingBelowMinimum($offering) ? null : $offering;
    }

    private function usableProductOffering(User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $tenant = $this->partnerTenantFor($user);

        if ($tenant === null) {
            return null;
        }

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)
            ->where('one_time_product_id', $product->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isProductOfferingBelowMinimum($offering) ? null : $offering;
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
