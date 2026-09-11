<?php

namespace Tests\Feature\Livewire\Auth\Register;

use App\Livewire\Auth\Register\OneTimePasswordRegistration;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

/**
 * The one-time-password registration form is the second door into signup, so
 * invite-only registration has to hold it shut too.
 */
class InviteOnlyOneTimePasswordRegistrationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.otp_login_enabled' => true, 'app.referral.only_registration' => true]);
    }

    public function test_the_form_asks_for_an_invitation_code(): void
    {
        $html = Livewire::test(OneTimePasswordRegistration::class)
            ->assertSee(__('Invitation code'))
            ->html();

        // A rendered, Livewire-bound <input> -- not the component tag echoed as text.
        $this->assertMatchesRegularExpression('/<input[^>]*name="referral_code"[^>]*wire:model="referralCode"/', $html);
        $this->assertStringNotContainsString('<x-input', $html);
    }

    public function test_the_form_does_not_ask_for_a_code_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        $html = Livewire::test(OneTimePasswordRegistration::class)->html();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="referral_code"/', $html);
    }

    public function test_registration_is_rejected_without_a_code(): void
    {
        Livewire::test(OneTimePasswordRegistration::class)
            ->set('email', 'otp-nocode@example.com')
            ->set('name', 'No Code')
            ->call('register')
            ->assertHasErrors('referral_code');

        $this->assertNull(User::where('email', 'otp-nocode@example.com')->first());
    }

    public function test_registration_is_rejected_with_a_code_nobody_owns(): void
    {
        Livewire::test(OneTimePasswordRegistration::class)
            ->set('email', 'otp-badcode@example.com')
            ->set('name', 'Bad Code')
            ->set('referralCode', 'REF-NOBODYOWNSTHIS')
            ->call('register')
            ->assertHasErrors('referral_code');

        $this->assertNull(User::where('email', 'otp-badcode@example.com')->first());
    }

    public function test_a_typed_code_registers_the_user_and_credits_the_referrer(): void
    {
        config(['app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = 'REF-'.strtoupper(uniqid());
        ReferralCode::create(['user_id' => $referrer->id, 'code' => $code]);

        Livewire::test(OneTimePasswordRegistration::class)
            ->set('email', 'otp-typed@example.com')
            ->set('name', 'Typed Code')
            ->set('referralCode', $code)
            ->call('register')
            ->assertHasNoErrors();

        $user = User::where('email', 'otp-typed@example.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $user->id,
            'referral_code' => $code,
        ]);
    }
}
