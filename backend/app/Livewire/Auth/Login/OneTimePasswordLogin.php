<?php

namespace App\Livewire\Auth\Login;

use App\Models\User;
use App\Services\OneTimePasswordService;
use App\Services\UserDashboardService;
use App\Validator\LoginValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Spatie\OneTimePasswords\Livewire\OneTimePasswordComponent;

class OneTimePasswordLogin extends OneTimePasswordComponent
{
    private OneTimePasswordService $oneTimePasswordService;

    private LoginValidator $loginValidator;

    private UserDashboardService $userDashboardService;

    public $recaptcha;

    public function mount(?string $redirectTo = null, ?string $email = ''): void
    {
        parent::mount($redirectTo, request()->query('email', $email));
    }

    public function render(): View
    {
        return view("livewire.auth.login.{$this->showViewName()}");
    }

    public function boot(
        OneTimePasswordService $oneTimePasswordService,
        LoginValidator $loginValidator,
        UserDashboardService $userDashboardService,
    ) {
        $this->oneTimePasswordService = $oneTimePasswordService;
        $this->loginValidator = $loginValidator;
        $this->userDashboardService = $userDashboardService;
    }

    public function submitEmail(): void
    {
        $fields = [
            'email' => $this->email,
        ];

        if (config('app.recaptcha_enabled')) {
            $fields[recaptchaFieldName()] = $this->recaptcha;
        }

        $validator = $this->loginValidator->validate($fields);

        if ($validator->fails()) {
            $this->resetReCaptcha();
            throw new ValidationException($validator);
        }

        $user = $this->findUser();

        if (! $user) {
            $this->addError('email', 'We could not find a user with that email address.');

            return;
        }

        if (! $this->oneTimePasswordService->sendCode($user)) {
            return;
        }

        $this->displayingEmailForm = false;
    }

    public function authenticate(Authenticatable $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        auth()->login($user);

        // Same landing as the password login (RedirectAwareTrait): straight
        // to the workspace dashboard, not the home redirect chain.
        if ($user instanceof User) {
            $this->redirectTo = $user->is_admin
                ? route('filament.admin.pages.dashboard')
                : $this->userDashboardService->getUserDashboardUrl($user);
        }
    }

    protected function resetReCaptcha()
    {
        $this->dispatch('reset-recaptcha');
    }
}
