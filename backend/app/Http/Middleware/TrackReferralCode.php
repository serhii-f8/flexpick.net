<?php

namespace App\Http\Middleware;

use App\Constants\ReferralConstants;
use App\Constants\SessionConstants;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One `rc` parameter drives both referral systems (spec §3.1, §3.3): the
 * session copy feeds the personal Referral row at registration; the cookie
 * remembers a partner for an anonymous visitor. First touch wins for the
 * cookie, and a signed-in user is attributed by the database, never here.
 */
class TrackReferralCode
{
    private const MAX_CODE_LENGTH = 64;

    public function __construct(
        private ReferralService $referralService,
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->referralService->isEnabled() || ! $request->has(ReferralConstants::HTTP_PARAM_REFERRAL_CODE)) {
            return $next($request);
        }

        $code = $request->input(ReferralConstants::HTTP_PARAM_REFERRAL_CODE);

        if (! is_string($code) || $code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return $next($request);
        }

        session([SessionConstants::REFERRAL_CODE => $code]);

        if (
            auth()->guest()
            && ! $this->partnerAttributionService->hasPartnerCookie($request)
            && $this->partnerAttributionService->resolveTenantForCode($code) !== null
        ) {
            $this->partnerAttributionService->queueCookie($code);
        }

        return $next($request);
    }
}
