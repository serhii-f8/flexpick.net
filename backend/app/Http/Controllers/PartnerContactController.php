<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use App\Services\PartnerContactResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The landing site is static and on another host, so it asks here who
 * invited this browser: it swaps FlexPick's contact details for the
 * referrer's, and sends the code back with its contact form so the
 * referrer is CC'd. The code is the one already in the referral link.
 */
class PartnerContactController extends Controller
{
    public function __invoke(PartnerContactResolver $contacts): JsonResponse
    {
        $referrer = $contacts->forVisitor(Auth::user());

        return response()
            ->json(['partner' => $referrer === null ? null : [
                'name' => $referrer->name,
                'email' => $referrer->email,
                'code' => ReferralCode::where('user_id', $referrer->id)->value('code'),
            ]])
            ->header('Cache-Control', 'no-store, private');
    }
}
