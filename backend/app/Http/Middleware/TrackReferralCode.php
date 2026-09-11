<?php

namespace App\Http\Middleware;

use App\Constants\ReferralConstants;
use App\Constants\SessionConstants;
use App\Services\PartnerAttributionService;
use App\Services\ReferralRegistrationGate;
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
        private ReferralRegistrationGate $referralRegistrationGate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Invite-only signup needs the code captured even where the reward
        // system is off: the register form's gate is fed from these stores.
        $isCapturing = $this->referralService->isEnabled() || $this->referralRegistrationGate->isActive();

        if (! $isCapturing || ! $request->has(ReferralConstants::HTTP_PARAM_REFERRAL_CODE)) {
            return $next($request);
        }

        $code = $request->input(ReferralConstants::HTTP_PARAM_REFERRAL_CODE);

        if (! is_string($code) || $code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return $next($request);
        }

        session([SessionConstants::REFERRAL_CODE => $code]);

        if (! auth()->guest() || ! $this->isTopLevelNavigation($request)) {
            return $next($request);
        }

        // Outlives the session, so a visitor who clicks a referral link today
        // can still register from it next week. Last touch wins, matching the
        // session copy; the partner cookie below stays first-touch-wins.
        if ($this->referralRegistrationGate->isValidCode($code)) {
            $this->referralRegistrationGate->rememberCode($code);
        }

        if (
            ! $this->partnerAttributionService->hasPartnerCookie($request)
            && $this->partnerAttributionService->resolveTenantForCode($code) !== null
        ) {
            $this->partnerAttributionService->queueCookie($code);
        }

        return $next($request);
    }

    /**
     * The partner cookie is set-once and first-touch-wins, so it must only
     * ever come from the visitor actually navigating to a partner link --
     * never from a third-party page embedding `<img src="…/?rc=CODE">` or
     * similar subresource requests, which would let anyone stuff another
     * site's visitors into a partner's attribution window (classic
     * affiliate cookie-stuffing). The session write above stays unguarded:
     * it also feeds the personal-referral-reward system and isn't set-once.
     */
    private function isTopLevelNavigation(Request $request): bool
    {
        $fetchDest = $request->header('Sec-Fetch-Dest');

        if ($fetchDest !== null) {
            return $fetchDest === 'document';
        }

        return ! $request->ajax() && $request->acceptsHtml();
    }
}
