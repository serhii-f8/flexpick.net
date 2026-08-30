<?php

namespace App\Services;

use App\Constants\SessionConstants;
use App\Models\PartnerReferralLink;
use App\Models\Tenant;

class PartnerAttributionService
{
    public function rememberPendingCode(string $code): void
    {
        session([SessionConstants::PARTNER_REFERRAL_CODE => $code]);
    }

    public function pendingCode(): ?string
    {
        return session(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function clearPendingCode(): void
    {
        session()->forget(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function resolveTenantForCode(string $code): ?Tenant
    {
        return PartnerReferralLink::where('code', $code)
            ->where('is_active', true)
            ->first()
            ?->tenant;
    }
}
