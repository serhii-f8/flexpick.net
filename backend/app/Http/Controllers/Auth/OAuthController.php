<?php

namespace App\Http\Controllers\Auth;

use App\Constants\ReferralConstants;
use App\Http\Controllers\Auth\Trait\RedirectAwareTrait;
use App\Models\OauthLoginProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GitProviders\GitConnectionService;
use App\Services\GitProviders\GitProviderResolver;
use App\Services\ReferralRegistrationGate;
use App\Services\UserService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends RegisterController
{
    use RedirectAwareTrait;

    /**
     * Deliberately does not call `parent::__construct()`: that constructor's
     * final statement is an unconditional `$this->middleware('guest')`,
     * applied to every action on the class with no `only()`/`except()`. It
     * would block the git-connection flow below, which requires an
     * *authenticated* tenant member to reach `redirect()`/`callback()`, and
     * controller middleware can't be conditioned on a query parameter. The
     * login-only behaviour that `guest` used to provide is instead
     * reproduced inline, as the first check in each action -- so an
     * authenticated user hitting the login path still bounces exactly as
     * before, before any provider lookup runs.
     */
    public function __construct(
        protected UserService $userService,
        protected ReferralRegistrationGate $referralRegistrationGate,
    ) {}

    public function redirect(string $provider)
    {
        if (request()->query('intent') === 'git_connection') {
            return $this->redirectForGitConnection($provider);
        }

        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $providerObj = OauthLoginProvider::where('provider_name', $provider)->firstOrFail();

        if (! $providerObj->enabled) {
            abort(404);
        }

        Redirect::setIntendedUrl(url()->previous());

        return Socialite::driver($provider)->redirect();
    }

    private function redirectForGitConnection(string $provider)
    {
        abort_unless(Auth::check(), 403);

        abort_unless(in_array($provider, GitProviderResolver::KNOWN_PROVIDER_NAMES, true), 404);

        // The workspace comes from the session-bound nonce the Git Connections
        // page issued, never from the query string: a GET link alone must not
        // be able to bind this user's provider account to a workspace.
        $tenant = app(GitConnectionService::class)
            ->consumeConnectNonce(request()->query('nonce'), Auth::user(), $provider);
        abort_unless($tenant !== null, 403);

        session(['git_connection_tenant_id' => $tenant->id]);

        $scopes = app(GitProviderResolver::class)->forProviderName($provider)->authorizationScopes();

        return Socialite::driver($provider)->scopes($scopes)->redirect();
    }

    public function callback(string $provider)
    {
        if (session()->has('git_connection_tenant_id')) {
            return $this->callbackForGitConnection($provider);
        }

        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $providerObj = OauthLoginProvider::where('provider_name', $provider)->firstOrFail();

        if (! $providerObj->enabled) {
            abort(404);
        }

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (Exception) {
            return redirect()->route('login');
        }

        $isRegistration = false;
        // Invite-only signup closes this door too: an existing account always
        // signs in, but a *new* one needs the same code the register form asks
        // for -- otherwise one click on "Continue with Google" bypasses it.
        $isBlockedByInviteOnly = false;
        DB::transaction(function () use ($provider, $oauthUser, &$isRegistration, &$isBlockedByInviteOnly) {
            $user = User::where('email', $oauthUser->email)->first();

            if ($user) {
                $user->update([
                    'name' => $oauthUser->name ?? $user->name ?? $oauthUser->nickname,
                ]);
            } else {
                if ($this->referralRegistrationGate->requiresCodeFor($oauthUser->getEmail())) {
                    $isBlockedByInviteOnly = true;

                    return;
                }

                $user = $this->userService->createUser([
                    'name' => $oauthUser->name ?? $oauthUser->nickname ?? '',
                    'email' => $oauthUser->email,
                ], true);

                $isRegistration = true;
            }

            $user->userParameters()->updateOrCreate(
                ['name' => 'oauth_provider_'.$provider],
                ['value' => $provider]
            );

            if (property_exists($oauthUser, 'id') && $oauthUser->id) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_id'],
                    ['value' => $oauthUser->id]
                );
            }

            if (property_exists($oauthUser, 'token') && $oauthUser->token) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_token'],
                    ['value' => $oauthUser->token]
                );
            }

            if (property_exists($oauthUser, 'refreshToken') && $oauthUser->refreshToken) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_refresh_token'],
                    ['value' => $oauthUser->refreshToken]
                );
            }

            if (property_exists($oauthUser, 'expiresIn') && $oauthUser->expiresIn) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_expires_in'],
                    ['value' => $oauthUser->expiresIn]
                );
            }

            if (property_exists($oauthUser, 'avatar') && $oauthUser->avatar) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_avatar'],
                    ['value' => $oauthUser->avatar]
                );
            }

            if (property_exists($oauthUser, 'nickname') && $oauthUser->nickname) {
                $user->userParameters()->updateOrCreate(
                    ['name' => 'oauth_'.$provider.'_nickname'],
                    ['value' => $oauthUser->nickname]
                );
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            Auth::login($user);
        });

        if ($isBlockedByInviteOnly) {
            return redirect()->route('register')->withErrors([
                ReferralConstants::REGISTRATION_CODE_FIELD => __('An invitation code is required to create an account.'),
            ]);
        }

        if ($isRegistration) {
            return redirect()->route('registration.thank-you');
        }

        return redirect($this->getRedirectUrl(Auth::user()));
    }

    private function callbackForGitConnection(string $provider)
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($provider, GitProviderResolver::KNOWN_PROVIDER_NAMES, true), 404);

        $tenantId = session()->pull('git_connection_tenant_id');
        $tenant = Tenant::findOrFail($tenantId);

        // Re-verify at the end of the round trip: membership or the permission
        // may have been revoked since the nonce was issued.
        abort_unless(app(GitConnectionService::class)->userMayManage($tenant, Auth::user()), 403);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (Exception $e) {
            // The page shows one generic message; without this the operator cannot
            // tell a wrong secret from a missing scope. Class and a clipped message
            // only -- never the exception object, whose trace and request could hold a token.
            Log::warning('Git connection OAuth callback failed.', [
                'provider' => $provider,
                'tenant_id' => $tenant->id,
                'exception' => $e::class,
                'message' => Str::limit($e->getMessage(), 500),
            ]);

            return redirect()->route('filament.dashboard.pages.git-connections', ['tenant' => $tenant])
                ->withErrors(['git_connection' => __('Connection was cancelled or failed. Please try again.')]);
        }

        app(GitConnectionService::class)->store($tenant, Auth::user(), $provider, $oauthUser);

        $label = app(GitProviderResolver::class)->forProviderName($provider)->label();

        return redirect()->route('filament.dashboard.pages.git-connections', ['tenant' => $tenant])
            ->with('status', __(':provider connected.', ['provider' => $label]));
    }
}
