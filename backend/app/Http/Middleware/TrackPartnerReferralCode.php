<?php

namespace App\Http\Middleware;

use App\Constants\PartnerConstants;
use App\Constants\SessionConstants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPartnerReferralCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has(PartnerConstants::HTTP_PARAM_PARTNER_CODE)) {
            session([SessionConstants::PARTNER_REFERRAL_CODE => $request->get(PartnerConstants::HTTP_PARAM_PARTNER_CODE)]);
        }

        return $next($request);
    }
}
