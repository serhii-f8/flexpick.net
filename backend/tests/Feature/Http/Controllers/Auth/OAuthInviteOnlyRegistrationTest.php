<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Constants\SessionConstants;
use App\Models\OauthLoginProvider;
use App\Models\ReferralCode;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Feature\FeatureTest;

/**
 * Social login is the third door into signup: without this gate, invite-only
 * registration would be one "Continue with Google" click away from bypassed.
 * Signing *in* an account that already exists is never blocked.
 */
class OAuthInviteOnlyRegistrationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withExceptionHandling();
        config(['app.referral.only_registration' => true]);

        OauthLoginProvider::updateOrCreate(
            ['provider_name' => 'google'],
            ['enabled' => true, 'name' => 'Google']
        );
    }

    public function test_a_new_account_is_refused_when_the_visitor_carries_no_code(): void
    {
        $this->fakeOauthUser('oauth-stranger@example.com');

        $response = $this->get(route('auth.oauth.callback', ['provider' => 'google']));

        $response->assertRedirect(route('register'));
        $this->assertNull(User::where('email', 'oauth-stranger@example.com')->first());
        $this->assertGuest();
    }

    public function test_a_new_account_is_created_when_the_visitor_carries_a_valid_code(): void
    {
        config(['app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = 'REF-'.strtoupper(uniqid());
        ReferralCode::create(['user_id' => $referrer->id, 'code' => $code]);
        $this->fakeOauthUser('oauth-invited@example.com');

        $this->withSession([SessionConstants::REFERRAL_CODE => $code])
            ->get(route('auth.oauth.callback', ['provider' => 'google']));

        $user = User::where('email', 'oauth-invited@example.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $user->id,
        ]);
    }

    public function test_an_existing_account_can_still_sign_in(): void
    {
        $existing = User::factory()->create(['email' => 'oauth-existing@example.com']);
        $this->fakeOauthUser('oauth-existing@example.com');

        $this->get(route('auth.oauth.callback', ['provider' => 'google']));

        $this->assertAuthenticatedAs($existing);
    }

    /**
     * Regression test: OAuthController used to inherit RegisterController's
     * `guest` middleware, which sent an already-authenticated visitor to
     * route('dashboard'). Its own constructor replaced that with an inline
     * `Auth::check()` redirect that briefly pointed at route('home') (the
     * pricing page) instead -- a real behaviour mismatch, not just a stray
     * word. Both redirect() and callback() must send them to the dashboard.
     */
    public function test_an_authenticated_user_hitting_the_plain_login_redirect_goes_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('auth.oauth.redirect', ['provider' => 'google']));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_an_authenticated_user_hitting_the_plain_login_callback_goes_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('auth.oauth.callback', ['provider' => 'google']));

        $response->assertRedirect(route('dashboard'));
    }

    private function fakeOauthUser(string $email): void
    {
        $socialiteUser = (new SocialiteUser)->map([
            'id' => (string) rand(1000, 9999),
            'name' => 'OAuth Person',
            'email' => $email,
        ]);
        $socialiteUser->token = 'fake-token';

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn(Mockery::mock(['user' => $socialiteUser]));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
