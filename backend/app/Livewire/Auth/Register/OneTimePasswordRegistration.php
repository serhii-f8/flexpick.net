<?php

namespace App\Livewire\Auth\Register;

use App\Constants\ReferralConstants;
use App\Services\OneTimePasswordService;
use App\Services\ReferralRegistrationGate;
use App\Services\UserService;
use App\Validator\RegisterValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OneTimePasswordRegistration extends Component
{
    public string $email;

    public string $name;

    /** Invite-only registration: the code a visitor without one has to type. */
    public string $referralCode = '';

    public $recaptcha;

    private RegisterValidator $registerValidator;

    private UserService $userService;

    private OneTimePasswordService $oneTimePasswordService;

    private ReferralRegistrationGate $referralRegistrationGate;

    public function boot(
        RegisterValidator $registerValidator,
        UserService $userService,
        OneTimePasswordService $oneTimePasswordService,
        ReferralRegistrationGate $referralRegistrationGate,
    ) {
        $this->registerValidator = $registerValidator;
        $this->userService = $userService;
        $this->oneTimePasswordService = $oneTimePasswordService;
        $this->referralRegistrationGate = $referralRegistrationGate;
    }

    public function render(): View
    {
        return view('livewire.auth.register.registration-form', [
            'requiresInvitationCode' => $this->referralRegistrationGate->requiresCodeInput(),
        ]);
    }

    public function register(): void
    {
        $userFields = [
            'email' => $this->email,
            'name' => $this->name,
        ];

        if ($this->referralRegistrationGate->isActive()) {
            $userFields[ReferralConstants::REGISTRATION_CODE_FIELD] = $this->referralCode;
        }

        if (config('app.recaptcha_enabled')) {
            $userFields[recaptchaFieldName()] = $this->recaptcha;
        }

        $validator = $this->registerValidator->validate($userFields, inviteOnly: true);

        if ($validator->fails()) {
            $this->resetReCaptcha();
            throw new ValidationException($validator);
        }

        $user = $this->userService->findByEmail($this->email);

        if ($user) {
            $this->addError('email', __('This email is already registered. Please log in instead.'));

            return;
        }

        $user = $this->userService->createUser($userFields, true);

        if (! $this->oneTimePasswordService->sendCode($user)) {
            $this->addError('email', __('Failed to send one-time password. Please try again later.'));

            return;
        }

        $this->redirect(route('login', ['email' => $this->email]));
    }

    protected function resetReCaptcha()
    {
        $this->dispatch('reset-recaptcha');
    }
}
