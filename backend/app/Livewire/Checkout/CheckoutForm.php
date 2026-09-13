<?php

namespace App\Livewire\Checkout;

use App\Constants\PaymentProviderConstants;
use App\Constants\ReferralConstants;
use App\Exceptions\LoginException;
use App\Exceptions\NoPaymentProvidersAvailableException;
use App\Models\User;
use App\Services\LoginService;
use App\Services\OneTimePasswordService;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\PaymentProviders\PaymentService;
use App\Services\ReferralRegistrationGate;
use App\Services\UserService;
use App\Validator\LoginValidator;
use App\Validator\RegisterValidator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Spatie\OneTimePasswords\Rules\OneTimePasswordRule;
use Throwable;

class CheckoutForm extends Component
{
    public $intro;

    public $name;

    public $email;

    public $password;

    public $paymentProvider;

    public $recaptcha;

    public $oneTimePassword;

    /** Typed invitation code, only rendered while invite-only signup is on. */
    public string $referralCode = '';

    public bool $showOtpForm = false;

    protected bool $otpVerified = false;

    protected $paymentProviders = [];

    public function mount(string $intro = '')
    {
        $this->intro = $intro;
    }

    public function render(PaymentService $paymentService)
    {
        return view('livewire.checkout.checkout-form', [
            'userExists' => $this->userExists($this->email),
            'paymentProviders' => $this->getPaymentProviders($paymentService),
            'otpEnabled' => config('app.otp_login_enabled'),
            'otpVerified' => $this->otpVerified,
        ]);
    }

    /** Whether the signup half of the form must ask for an invitation code. */
    protected function requiresInvitationCode(): bool
    {
        return app(ReferralRegistrationGate::class)->requiresCodeInput();
    }

    public function handleLoginOrRegistration(
        LoginValidator $loginValidator,
        RegisterValidator $registerValidator,
        UserService $userService,
        LoginService $loginService,
    ) {
        if (! auth()->check()) {
            if (config('app.otp_login_enabled')) {
                $this->verifyOtpAndProceed($userService, $loginService);
            } else {
                if ($this->userExists($this->email)) {
                    $this->loginUser($loginValidator, $loginService);
                } else {
                    $this->registerUser($registerValidator, $userService);
                }
            }
        }

        $user = auth()->user();
        if (! $user) {
            $this->redirect(route('login'));

            return;
        }

        $this->handleBlockedUser($user);
    }

    protected function handleBlockedUser(User $user)
    {
        if ($user->is_blocked) {
            auth()->logout();
            throw ValidationException::withMessages([
                'email' => __('Your account is blocked, please contact support.'),
            ]);
        }
    }

    protected function loginUser(LoginValidator $loginValidator, LoginService $loginService)
    {
        $fields = [
            'email' => $this->email,
            'password' => $this->password,
        ];

        if (config('app.recaptcha_enabled')) {
            $fields[recaptchaFieldName()] = $this->recaptcha;
        }

        $validator = $loginValidator->validate($fields);

        if ($validator->fails()) {
            $this->resetReCaptcha();
            throw new ValidationException($validator);
        }

        try {
            $result = $loginService->attempt([
                'email' => $this->email,
                'password' => $this->password,
            ], true);
        } catch (Throwable $e) {  // usually thrown when 2FA is enabled so user need to be redirected to login page to enter 2FA code
            throw new LoginException;
        }

        if (! $result) {
            $this->resetReCaptcha();
            throw ValidationException::withMessages([
                'email' => __('Wrong email or password'),
            ]);
        }
    }

    protected function registerUser(RegisterValidator $registerValidator, UserService $userService)
    {
        $fields = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ];

        if (config('app.recaptcha_enabled')) {
            $fields[recaptchaFieldName()] = $this->recaptcha;
        }

        $fields = $this->withInvitationCode($fields);

        $validator = $registerValidator->validate($fields, passwordConfirmed: false, inviteOnly: true);

        if ($validator->fails()) {
            $this->resetReCaptcha();
            throw new ValidationException($validator);
        }

        $user = $userService->createUser($this->withInvitationCode([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]), true);

        auth()->login($user);

        $user->sendEmailVerificationNotification();

        return $user;
    }

    protected function userExists(?string $email): bool
    {
        if ($email === null) {
            return false;
        }

        return User::where('email', $email)->exists();
    }

    protected function getPaymentProviders(PaymentService $paymentService)
    {
        if (count($this->paymentProviders) > 0) {
            return $this->paymentProviders;
        }

        $this->paymentProviders = $paymentService->getActivePaymentProviders(true);

        if (empty($this->paymentProviders)) {
            logger()->error('No payment providers available');

            throw new NoPaymentProvidersAvailableException('No payment providers available');
        }

        if ($this->paymentProvider === null) {
            $this->paymentProvider = $this->paymentProviders[0]->getSlug();
        }

        return $this->paymentProviders;
    }

    protected function resetReCaptcha()
    {
        $this->dispatch('reset-recaptcha');
    }

    public function sendOtpCode(
        UserService $userService,
        LoginValidator $loginValidator,
        RegisterValidator $registerValidator,
        OneTimePasswordService $oneTimePasswordService,
    ) {
        if (! config('app.otp_login_enabled')) {
            return;
        }

        $user = $userService->findByEmail($this->email);

        if ($user) {
            $fields = [
                'email' => $this->email,
            ];

            if (config('app.recaptcha_enabled')) {
                $fields[recaptchaFieldName()] = $this->recaptcha;
            }

            $validator = $loginValidator->validate($fields);

            if ($validator->fails()) {
                $this->resetReCaptcha();
                throw new ValidationException($validator);
            }
        } else {

            $fields = [
                'name' => $this->name,
                'email' => $this->email,
            ];

            if (config('app.recaptcha_enabled')) {
                $fields[recaptchaFieldName()] = $this->recaptcha;
            }

            $fields = $this->withInvitationCode($fields);

            $validator = $registerValidator->validate($fields, passwordConfirmed: false, inviteOnly: true);

            if ($validator->fails()) {
                $this->resetReCaptcha();
                throw new ValidationException($validator);
            }

            $user = $userService->createUser($this->withInvitationCode([
                'name' => $this->name,
                'email' => $this->email,
            ]), true);
        }

        if (! $oneTimePasswordService->sendCode($user)) {
            $this->resetReCaptcha();
            $this->addError('email', __('Failed to send one-time password. Please try again later.'));

            return;
        }

        $this->showOtpForm = true;
    }

    public function verifyOtpAndProceed(
        UserService $userService,
        LoginService $loginService,
    ) {
        if (! config('app.otp_login_enabled')) {
            return;
        }

        $user = $userService->findByEmail($this->email);

        if (! $user) {
            $this->addError('oneTimePassword', __('User not found.'));

            return;
        }

        $this->validate([
            'oneTimePassword' => ['required', new OneTimePasswordRule($user)],
        ]);

        $loginService->authenticateUser($user, true);

        $this->handleBlockedUser($user);

        $this->otpVerified = true;

        // Force re-render to update button state
        $this->dispatch('$refresh');
    }

    public function resendOtpCode(
        UserService $userService,
        LoginValidator $loginValidator,
        RegisterValidator $registerValidator,
        OneTimePasswordService $oneTimePasswordService,
    ) {
        $this->sendOtpCode($userService, $loginValidator, $registerValidator, $oneTimePasswordService);
    }

    public function isCheckoutButtonEnabled(): bool
    {
        $otpEnabled = config('app.otp_login_enabled');
        $isAuthenticated = auth()->check();

        if (! $otpEnabled || $isAuthenticated || $this->otpVerified) {
            return true;
        }

        if ($this->showOtpForm) {
            return ! empty(trim($this->email)) && ! empty(trim($this->oneTimePassword));
        }

        return false;
    }

    /**
     * A partner price is only ever payable in cash (spec §1, §8.1), so when a
     * usable offering is driving the price, Offline is the only provider we
     * may present.
     *
     * This never empties the list, because PartnerPricingResolver refuses to
     * return an offering unless Offline would survive every filter the two
     * callers apply: the row is active AND open to new payments, Offline
     * supports the plan's type, and — for a plan with a trial — this buyer is
     * still trial-eligible, so checkout will not ask for a provider that can
     * skip the trial. The remaining filters Offline passes unconditionally
     * (supportsSetupFees, supportsOneTimePurchaseProductQuantity), and
     * supportsSeatBasedWithIncludedSeats is only ever required of a seat-based
     * price, which Offline's flat-rate-only supportsPlan() has already
     * excluded. Any new filter added to PaymentService must be reflected in
     * the resolver's gates, or that claim stops holding and the customer gets
     * an unhandled NoPaymentProvidersAvailableException.
     *
     * @param  array<int, PaymentProviderInterface>  $providers
     * @return array<int, PaymentProviderInterface>
     */
    public static function restrictToPartnerProviders(array $providers, ?object $usableOffering): array
    {
        if ($usableOffering === null) {
            return $providers;
        }

        return array_values(array_filter(
            $providers,
            fn ($provider): bool => $provider->getSlug() === PaymentProviderConstants::OFFLINE_SLUG,
        ));
    }

    /**
     * The checkout form registers guests too, so it enforces invite-only
     * signup exactly like /register. A referred visitor already carries the
     * code in session or cookie and is let through by the gate without
     * typing anything; a cold visitor is asked for one.
     */
    private function withInvitationCode(array $fields): array
    {
        if (app(ReferralRegistrationGate::class)->isActive()) {
            $fields[ReferralConstants::REGISTRATION_CODE_FIELD] = $this->referralCode;
        }

        return $fields;
    }
}
