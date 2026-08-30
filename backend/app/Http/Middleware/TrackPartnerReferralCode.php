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
            $code = $request->input(PartnerConstants::HTTP_PARAM_PARTNER_CODE);

            if (is_string($code) && $code !== '' && mb_strlen($code) <= 64) {
                session([SessionConstants::PARTNER_REFERRAL_CODE => $code]);
            }
        }

        return $next($request);
    }
}
