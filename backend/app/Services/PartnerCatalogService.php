<?php

namespace App\Services;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;

class PartnerCatalogService
{
    public function __construct(
        private CurrencyService $currencyService,
    ) {}

    public function planBasePrice(Plan $plan): int
    {
        $price = PlanPrice::where('plan_id', $plan->id)
            ->where('currency_id', $this->currencyService->getCurrency()->id)
            ->value('price');

        if ($price === null) {
            throw new PartnerOfferingValidationException("Plan [{$plan->slug}] has no price in the default currency.");
        }

        return (int) $price;
    }

    public function setPlanOffering(Tenant $tenant, Plan $plan, int $price, array $quotaOverrides, bool $isEnabled): PartnerPlanOffering
    {
        $this->assertPriceAtOrAboveBase($price, $this->planBasePrice($plan));
        $this->assertQuotasValid(
            $quotaOverrides,
            (array) data_get($plan->product->metadata, 'reseller_quota_keys', []),
            (array) ($plan->product->metadata ?? []),
        );

        return PartnerPlanOffering::updateOrCreate(
            ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ['price' => $price, 'quota_overrides' => $quotaOverrides, 'is_enabled' => $isEnabled],
        );
    }

    private function assertPriceAtOrAboveBase(int $price, int $basePrice): void
    {
        if ($price < $basePrice) {
            throw new PartnerOfferingValidationException("Price must be at least the base price of {$basePrice}.");
        }
    }

    private function assertQuotasValid(array $quotaOverrides, array $allowedKeys, array $baseMetadata): void
    {
        foreach ($quotaOverrides as $key => $value) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new PartnerOfferingValidationException("Quota key [{$key}] is not configurable for this item.");
            }

            $baseValue = (int) data_get($baseMetadata, $key, 0);

            if ((int) $value < $baseValue) {
                throw new PartnerOfferingValidationException("Quota [{$key}] must be at least {$baseValue}.");
            }
        }
    }
}
