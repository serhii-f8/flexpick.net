<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;
use App\Models\OneTimeProduct;
use App\Models\User;
use App\Services\PartnerPricingResolver;

/**
 * What an audit tier costs the person looking at it. A customer a partner
 * referred buys through that partner, so they are quoted the partner's price
 * -- the one /pricing and checkout use -- and a tier their partner does not
 * resell has no price for them at all, since checkout would refuse it
 * (catalog restriction). Everyone else gets the catalog price.
 */
class AuditTierPricing
{
    public function __construct(
        private PartnerPricingResolver $partnerPricing,
    ) {}

    public function priceCentsFor(AuditTier $tier, ?User $buyer): ?int
    {
        $base = $tier->priceCents();

        if ($base === null || $this->partnerPricing->resolvePartnerTenant($buyer) === null) {
            return $base;
        }

        $product = OneTimeProduct::query()->where('slug', $tier->productSlug())->first();

        return $product === null ? null : $this->partnerPricing->productPrice($buyer, $product);
    }

    public function labelWithPrice(AuditTier $tier, ?User $buyer): string
    {
        $cents = $this->priceCentsFor($tier, $buyer);

        return $cents === null
            ? $tier->label()
            : __(':label — $:price', ['label' => $tier->label(), 'price' => number_format($cents / 100)]);
    }
}
