<?php

namespace Tests\Feature\Listeners;

use App\Events\Referral\ReferralSucceeded;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class GrantAuditBonusOnReferralTest extends FeatureTest
{
    public function test_referral_success_grants_bonus_run(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'testcode',
            'status' => 'rewarded',
        ]);

        ReferralSucceeded::dispatch($referrer, $referred, $referral);
        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $value = UserParameter::query()
            ->where('user_id', $referrer->id)
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->value('value');

        $this->assertSame('2', $value);
        $this->assertSame(2, app(AuditEntitlementService::class)->freeRunsLimit($referrer->email));
    }
}
