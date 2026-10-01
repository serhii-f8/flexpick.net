<?php

namespace App\Services;

use App\Models\ReferralCode;
use App\Models\User;

/**
 * The person who invited this visitor, shown in place of FlexPick's own
 * contact details on every page a referred buyer sees (the storefront, the
 * landing site) and CC'd on the operator email their contact form produces.
 *
 * The referrer is the owner of the code the visitor arrived through. A
 * signed-in user is attributed by tenant, not by code, so for them it is the
 * tenant's canonical code owner (codeForTenant) -- the same member whose code
 * the fp_rc cookie is re-issued from after login.
 */
class PartnerContactResolver
{
    public function __construct(
        private PartnerAttributionService $attributionService,
        private PartnerPricingResolver $pricingResolver,
    ) {}

    public function forVisitor(?User $user = null): ?User
    {
        $tenant = $this->pricingResolver->resolvePartnerTenant($user);

        if ($tenant === null) {
            return null;
        }

        $code = $this->attributionService->cookieCode();

        if ($code === null || ! $this->attributionService->resolveTenantForCode($code)?->is($tenant)) {
            $code = $this->attributionService->codeForTenant($tenant);
        }

        return $code === null ? null : $this->referrerFor($code);
    }

    /** Only the owner of a code that still resolves to an active partner. */
    public function forCode(?string $code): ?User
    {
        if ($code === null || $code === '' || $this->attributionService->resolveTenantForCode($code) === null) {
            return null;
        }

        return $this->referrerFor($code);
    }

    private function referrerFor(string $code): ?User
    {
        /** @var User|null */
        return ReferralCode::where('code', $code)->first()?->user;
    }
}
