<?php

namespace Tests\Feature\Http\Middleware;

use App\Constants\ReferralConstants;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ReferralCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ReferralService;
use Tests\Feature\FeatureTest;

class TrackReferralCodeTest extends FeatureTest
{
    public function test_referral_code_is_stored_in_session(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $user = User::factory()->create();
        ReferralCode::create([
            'user_id' => $user->id,
            'code' => 'TESTCODE123',
        ]);

        $response = $this->get('/login?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'=TESTCODE123');

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, 'TESTCODE123');
    }

    public function test_referral_code_is_stored_from_any_route(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $response = $this->get('/register?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'=ANYCODE456');

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, 'ANYCODE456');
    }

    public function test_referral_code_is_not_stored_when_not_present(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $response = $this->get('/login');

        $response->assertSessionMissing(SessionConstants::REFERRAL_CODE);
    }

    public function test_referral_code_is_overwritten_with_new_code(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $this->withSession([SessionConstants::REFERRAL_CODE => 'OLDCODE123']);

        $response = $this->get('/login?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'=NEWCODE456');

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, 'NEWCODE456');
    }

    public function test_referral_code_is_not_stored_when_system_disabled(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => false]);

        $response = $this->get('/login?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'=TESTCODE');

        $response->assertSessionMissing(SessionConstants::REFERRAL_CODE);
    }

    public function test_referral_code_persists_across_requests(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $this->get('/login?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'=PERSISTENT1');

        $response = $this->get('/login');
        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, 'PERSISTENT1');

        $response = $this->get('/register');
        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, 'PERSISTENT1');
    }

    private function partnerCode(): string
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $member = $this->createUser($tenant);

        return app(ReferralService::class)->getOrCreateReferralCode($member)->code;
    }

    public function test_a_partner_code_sets_the_attribution_cookie_for_a_guest(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->get('/login?rc='.$code);

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $code);
        $response->assertCookie(config('partner.cookie_name'), $code);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('partner.cookie_name'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(now()->addDays(365)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_a_non_partner_code_sets_no_cookie(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = app(ReferralService::class)->getOrCreateReferralCode($referrer)->code;

        $response = $this->get('/login?rc='.$code);

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $code);
        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_an_existing_partner_cookie_is_never_overwritten(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $first = $this->partnerCode();
        $second = $this->partnerCode();

        $response = $this->withCookie(config('partner.cookie_name'), $first)->get('/login?rc='.$second);

        // Nothing queued: the browser keeps the first partner's cookie.
        $response->assertCookieMissing(config('partner.cookie_name'));
        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $second);
    }

    public function test_a_cookie_that_no_longer_resolves_can_be_replaced(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->withCookie(config('partner.cookie_name'), 'REF-LAPSEDPARTNER')->get('/login?rc='.$code);

        $response->assertCookie(config('partner.cookie_name'), $code);
    }

    public function test_a_signed_in_user_never_receives_the_cookie(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get('/?rc='.$code);

        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_the_old_parameter_names_are_ignored(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->get('/login?referralCode='.$code.'&partnerCode='.$code);

        $response->assertSessionMissing(SessionConstants::REFERRAL_CODE);
        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_an_array_or_overlong_value_is_ignored(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $this->get('/login?rc[]=x')->assertSessionMissing(SessionConstants::REFERRAL_CODE);
        $this->get('/login?rc='.str_repeat('a', 65))->assertSessionMissing(SessionConstants::REFERRAL_CODE);
    }
}
