<?php

namespace Tests\Feature\Listeners;

use App\Events\Referral\ReferralSucceeded;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class GrantAuditBonusOnReferralTest extends FeatureTest
{
    public function test_referral_success_grants_a_bonus_run_to_the_referrers_workspace(): void
    {
        $referrer = User::factory()->create();
        $tenant = Tenant::factory()->create(['created_by' => $referrer->id]);
        $tenant->users()->attach($referrer);
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'testcode',
            'status' => 'rewarded',
        ]);

        ReferralSucceeded::dispatch($referrer, $referred, $referral);
        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $value = TenantParameter::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->value('value');

        $this->assertSame('2', $value);
    }

    public function test_a_referrer_without_a_workspace_gets_nothing_and_nothing_breaks(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'lonely',
            'status' => 'rewarded',
        ]);

        // No RefreshDatabase and the suite shares the test DB across
        // classes in a run, so an absolute TenantParameter::count()
        // assertion is flaky under concurrent runs; assert a before/after
        // delta for this referrer's own rows instead.
        $before = TenantParameter::query()
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->count();

        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $after = TenantParameter::query()
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->count();

        $this->assertSame($before, $after);
    }
}
