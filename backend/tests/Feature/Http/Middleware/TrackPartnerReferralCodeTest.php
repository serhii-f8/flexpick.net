<?php

namespace Tests\Feature\Http\Middleware;

use App\Constants\PartnerConstants;
use App\Constants\SessionConstants;
use Tests\Feature\FeatureTest;

class TrackPartnerReferralCodeTest extends FeatureTest
{
    public function test_partner_code_is_stored_in_session(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=TESTCODE123');

        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'TESTCODE123');
    }

    public function test_partner_code_is_not_stored_when_absent(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/login');

        $response->assertSessionMissing(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function test_partner_code_persists_across_requests(): void
    {
        $this->withExceptionHandling();

        $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=PERSIST1');

        $response = $this->get('/register');
        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'PERSIST1');
    }

    public function test_partner_code_is_tracked_regardless_of_the_consumer_referral_flag(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => false]);

        $response = $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=STILLWORKS');

        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'STILLWORKS');
    }

    public function test_array_shaped_partner_code_is_ignored_without_error(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'[]=x');

        $response->assertSessionMissing(SessionConstants::PARTNER_REFERRAL_CODE);
        $response->assertStatus(200);
    }
}
