<?php

namespace App\Listeners\Referral;

use App\Events\Referral\ReferralSucceeded;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;

class GrantAuditBonusOnReferral
{
    public function handle(ReferralSucceeded $event): void
    {
        $parameter = UserParameter::firstOrCreate(
            ['user_id' => $event->referrer->id, 'name' => AuditEntitlementService::BONUS_PARAM],
            ['value' => '0'],
        );

        $parameter->update(['value' => (string) ((int) $parameter->value + 1)]);
    }
}
