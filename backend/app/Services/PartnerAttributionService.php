<?php

namespace App\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SessionConstants;
use App\Models\PartnerReferralLink;
use App\Models\Tenant;
use App\Models\User;

class PartnerAttributionService
{
    public function __construct(
        private PartnerCapabilityService $partnerCapabilityService,
    ) {}

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

    public function attribute(User $user, PartnerAttributionSource $source): void
    {
        if ($user->partner_tenant_id !== null) {
            return;
        }

        $tenant = $this->resolvePendingActivePartnerTenant();

        if ($tenant === null) {
            return;
        }

        $user->update([
            'partner_tenant_id' => $tenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => $source->value,
        ]);

        $this->clearPendingCode();
    }

    public function pendingCodeConflictsWithExisting(User $user): bool
    {
        if ($user->partner_tenant_id === null) {
            return false;
        }

        $code = $this->pendingCode();

        if ($code === null) {
            return false;
        }

        $tenant = $this->resolveTenantForCode($code);

        return $tenant !== null && $tenant->id !== $user->partner_tenant_id;
    }

    private function resolvePendingActivePartnerTenant(): ?Tenant
    {
        $code = $this->pendingCode();

        if ($code === null) {
            return null;
        }

        $tenant = $this->resolveTenantForCode($code);

        if ($tenant === null || ! $this->partnerCapabilityService->tenantIsActivePartner($tenant)) {
            return null;
        }

        return $tenant;
    }
}
