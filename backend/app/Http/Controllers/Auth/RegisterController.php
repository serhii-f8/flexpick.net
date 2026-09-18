<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferralRegistrationGate;
use App\Services\TenantService;
use App\Services\UserDashboardService;
use App\Services\UserService;
use App\Validator\RegisterValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    //    protected $redirectTo = '/email/verify';

    public function __construct(
        protected RegisterValidator $registerValidator,
        protected UserService $userService,
        protected TenantService $tenantService,
        protected ReferralRegistrationGate $referralRegistrationGate,
        protected UserDashboardService $userDashboardService,
    ) {
        $this->middleware('guest');
    }

    /**
     * Pending workspace invitations first, then a protected page the auth
     * middleware bounced them off, then their own dashboard -- never the
     * page they happened to register from.
     */
    public function redirectPath()
    {
        $user = auth()->user();

        if ($user && $this->tenantService->getUserInvitationCount($user) > 0) {
            return route('invitations');
        }

        if (Redirect::getIntendedUrl() !== null) {
            return Redirect::getIntendedUrl();
        }

        return $user ? $this->userDashboardService->getUserDashboardUrl($user) : route('home');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return Validator
     */
    protected function validator(array $data)
    {
        return $this->registerValidator->validate($data, inviteOnly: true);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return User
     */
    protected function create(array $data)
    {
        return $this->userService->createUser($data);
    }

    /**
     * Show the application registration form.
     *
     * @return View
     */
    public function showRegistrationForm()
    {
        return view('auth.register', [
            'isOtpLoginEnabled' => config('app.otp_login_enabled'),
            'isInviteOnly' => $this->referralRegistrationGate->isActive(),
            'requiresInvitationCode' => $this->referralRegistrationGate->requiresCodeInput(),
        ]);
    }
}
