<?php

namespace App\Listeners\Referral;

use App\Events\Referral\ReferralSucceeded;
use App\Models\TenantParameter;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\PrimaryTenantResolver;

class GrantAuditBonusOnReferral
{
    public function __construct(
        private PrimaryTenantResolver $primaryTenants,
    ) {}

    public function handle(ReferralSucceeded $event): void
    {
        // Free runs are a workspace quota, so the reward lands on the
        // referrer's workspace; a referrer with none has nowhere to spend it.
        $tenant = $this->primaryTenants->resolve($event->referrer);

        if ($tenant === null) {
            return;
        }

        $parameter = TenantParameter::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => AuditEntitlementService::BONUS_PARAM],
            ['value' => '0'],
        );

        $parameter->update(['value' => (string) ((int) $parameter->value + 1)]);
    }
}
