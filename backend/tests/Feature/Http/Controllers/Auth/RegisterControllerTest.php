<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Constants\SessionConstants;
use App\Models\Invitation;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\ReferralRegistrationGate;
use Tests\Feature\FeatureTest;

class RegisterControllerTest extends FeatureTest
{
    public function test_recaptcha_is_viewed_if_enabled()
    {
        config(['app.recaptcha_enabled' => true]);

        $response = $this->get(route('register'));

        $response->assertSee('g-recaptcha');
    }

    public function test_recaptcha_is_not_viewed_if_disabled()
    {
        config(['app.recaptcha_enabled' => false]);

        $response = $this->get(route('register'));

        $response->assertDontSee('g-recaptcha');
    }

    public function test_the_register_page_asks_for_an_invitation_code_when_signup_is_invite_only(): void
    {
        config(['app.referral.only_registration' => true]);

        $response = $this->get(route('register'));

        $response->assertSee(__('Invitation code'));
        $response->assertSee('name="referral_code"', false);
    }

    public function test_the_register_page_does_not_ask_for_a_code_when_the_visitor_already_carries_one(): void
    {
        config(['app.referral.only_registration' => true]);
        $code = $this->referralCode();

        $response = $this->withSession([SessionConstants::REFERRAL_CODE => $code])->get(route('register'));

        $response->assertDontSee('name="referral_code"', false);
        $response->assertSee(__('Your invitation has been applied.'));
    }

    public function test_the_register_page_asks_for_nothing_extra_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        $response = $this->get(route('register'));

        $response->assertDontSee('name="referral_code"', false);
    }

    public function test_registration_is_rejected_without_a_code_when_signup_is_invite_only(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.only_registration' => true]);

        $response = $this->post(route('register'), $this->registrationData('nocode@example.com'));

        $response->assertSessionHasErrors('referral_code');
        $this->assertNull(User::where('email', 'nocode@example.com')->first());
        $this->assertGuest();
    }

    public function test_registration_is_rejected_with_a_code_nobody_owns(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.only_registration' => true]);

        $response = $this->post(route('register'), $this->registrationData('badcode@example.com', [
            'referral_code' => 'REF-NOBODYOWNSTHIS',
        ]));

        $response->assertSessionHasErrors('referral_code');
        $this->assertNull(User::where('email', 'badcode@example.com')->first());
    }

    public function test_a_typed_invitation_code_registers_the_user_and_credits_the_referrer(): void
    {
        config(['app.referral.only_registration' => true, 'app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = $this->referralCode($referrer);

        $this->post(route('register'), $this->registrationData('typed@example.com', [
            'referral_code' => $code,
        ]));

        $user = User::where('email', 'typed@example.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $user->id,
            'referral_code' => $code,
        ]);
    }

    public function test_a_code_carried_in_the_cookie_is_applied_without_the_visitor_typing_it(): void
    {
        config(['app.referral.only_registration' => true, 'app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = $this->referralCode($referrer);

        $this->withCookie(app(ReferralRegistrationGate::class)->cookieName(), $code)
            ->post(route('register'), $this->registrationData('cookied@example.com'));

        $user = User::where('email', 'cookied@example.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $user->id,
        ]);
    }

    public function test_a_workspace_invitee_registers_without_a_code(): void
    {
        config(['app.referral.only_registration' => true]);
        Invitation::factory()->create([
            'email' => 'invited@example.com',
            'user_id' => User::factory()->create()->id,
            'tenant_id' => $this->createTenant()->id,
        ]);

        $this->post(route('register'), $this->registrationData('invited@example.com'));

        $this->assertNotNull(User::where('email', 'invited@example.com')->first());
    }

    public function test_public_registration_still_works_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        $this->post(route('register'), $this->registrationData('public@example.com'));

        $this->assertNotNull(User::where('email', 'public@example.com')->first());
    }

    private function registrationData(string $email, array $extra = []): array
    {
        return array_merge([
            'name' => 'Test Person',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ], $extra);
    }

    private function referralCode(?User $owner = null): string
    {
        $code = 'REF-'.strtoupper(uniqid());

        ReferralCode::create([
            'user_id' => ($owner ?? User::factory()->create())->id,
            'code' => $code,
        ]);

        return $code;
    }
}
