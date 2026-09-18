<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Trait\RedirectAwareTrait;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginService;
use App\Validator\LoginValidator;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;
    use RedirectAwareTrait;

    public function __construct(
        private LoginValidator $loginValidator,
        private LoginService $loginService,
    ) {
        $this->middleware('guest')->except('logout');
    }

    /**
     * The previous page is deliberately NOT remembered as the intended URL:
     * after logging in a user goes to their dashboard, not back to the
     * pricing page they clicked "Log in" from. The auth middleware still
     * sets an intended URL for a protected page, and that one is honoured.
     */
    public function showLoginForm()
    {
        return view('auth.login', [
            'isOtpLoginEnabled' => config('app.otp_login_enabled'),
        ]);
    }

    protected function authenticated(Request $request, User $user)
    {
        if ($user->is_blocked) {
            $this->guard()->logout();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been blocked. Please contact support.',
            ]);
        }

        return redirect($this->getRedirectUrl($user));
    }

    protected function validateLogin(Request $request)
    {
        $this->loginValidator->validateRequest($request);
    }

    protected function attemptLogin(Request $request)
    {
        return $this->loginService->attempt($this->credentials($request), $request->boolean('remember'));
    }
}
