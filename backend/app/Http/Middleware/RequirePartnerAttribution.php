<?php

namespace App\Http\Middleware;

use App\Services\PartnerPricingResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront and every purchase entry point open only to a buyer a
 * partner can price: a guest carrying the partner cookie (set by the
 * referral link they arrived through) or a user attributed to a partner
 * whose plan is still active. Anyone else would be shown -- and could buy
 * at -- base prices, and there is no such thing as a sale without a
 * referrer.
 *
 * The rule is the resolver's own, not a re-derivation: whoever it resolves
 * a partner tenant for is exactly who the storefront can quote partner
 * prices to, so display and access can never disagree.
 */
class RequirePartnerAttribution
{
    public function __construct(
        private PartnerPricingResolver $partnerPricingResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->partnerPricingResolver->resolvePartnerTenant($request->user()) === null) {
            return response()->view('pricing.invite-only', status: 403);
        }

        return $next($request);
    }
}
