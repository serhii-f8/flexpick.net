<?php

namespace Tests\Feature\Validator;

use App\Validator\RegisterValidator;
use Tests\Feature\FeatureTest;

class RegisterValidatorTest extends FeatureTest
{
    /**
     * The checkout forms register guests through this validator too, and
     * invite-only signup deliberately leaves that door open: a buyer is a
     * customer, not a stranger. So the gate is opt-in per caller, never on
     * by default.
     */
    public function test_invite_only_is_not_enforced_unless_the_caller_asks_for_it(): void
    {
        config(['app.referral.only_registration' => true]);

        $validator = app(RegisterValidator::class)->validate([
            'name' => 'Checkout Buyer',
            'email' => 'buyer-'.uniqid().'@example.com',
            'password' => 'password1234',
        ], passwordConfirmed: false);

        $this->assertFalse($validator->fails());
    }

    public function test_invite_only_requires_a_code_when_the_caller_asks_for_it(): void
    {
        config(['app.referral.only_registration' => true]);

        $validator = app(RegisterValidator::class)->validate([
            'name' => 'Stranger',
            'email' => 'stranger-'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ], inviteOnly: true);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('referral_code', $validator->errors()->toArray());
    }

    public function test_invite_only_is_a_no_op_while_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        $validator = app(RegisterValidator::class)->validate([
            'name' => 'Anyone',
            'email' => 'anyone-'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ], inviteOnly: true);

        $this->assertFalse($validator->fails());
    }
}
