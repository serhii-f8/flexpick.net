<?php

namespace App\Validator;

use App\Constants\ReferralConstants;
use App\Services\ReferralRegistrationGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterValidator
{
    public function __construct(
        private ReferralRegistrationGate $referralRegistrationGate,
    ) {}

    /**
     * @param  bool  $inviteOnly  Enforce REFERRAL_ONLY_REGISTRATION. Opt-in per
     *                            caller: the public register forms pass true; the
     *                            checkout forms, which also register guests, do
     *                            not -- a buyer is never turned away for lacking
     *                            an invitation.
     */
    public function validate(array $fields, bool $passwordConfirmed = true, bool $inviteOnly = false)
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        ];

        if (! config('app.otp_login_enabled', false)) {
            $rules['password'] = ['required', 'string', 'min:8'];

            if ($passwordConfirmed) {
                $rules['password'][] = 'confirmed';
            }
        }

        if (config('app.recaptcha_enabled')) {
            $rules[recaptchaFieldName()] = recaptchaRuleName();
        }

        // Invite-only signup. Both registration forms (password and one-time
        // password) run through here, so the gate cannot be bypassed by
        // picking the other one.
        $messages = [];

        if ($inviteOnly && $this->referralRegistrationGate->requiresCodeFor($fields['email'] ?? null)) {
            $rules[ReferralConstants::REGISTRATION_CODE_FIELD] = [
                'required',
                'string',
                'max:64',
                Rule::exists('referral_codes', 'code'),
            ];

            $messages = [
                ReferralConstants::REGISTRATION_CODE_FIELD.'.required' => __('An invitation code is required to create an account.'),
                ReferralConstants::REGISTRATION_CODE_FIELD.'.exists' => __('That invitation code is not valid.'),
            ];
        }

        return Validator::make($fields, $rules, $messages);
    }
}
