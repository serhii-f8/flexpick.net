<?php

namespace App\Services;

use App\Constants\InvitationStatus;
use App\Constants\SessionConstants;
use App\Models\Invitation;
use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Invite-only registration (REFERRAL_ONLY_REGISTRATION).
 *
 * When active, an account is only created for a visitor who arrived through
 * somebody's referral link, typed a valid code, or was invited to a workspace
 * by email. The gate answers those questions in one place so the register
 * form, the register validator and the OAuth callback cannot disagree.
 *
 * A code is remembered in three places, checked in this order: the session
 * (written by TrackReferralCode), our own long-lived referral cookie, and the
 * partner attribution cookie. All three hold a ReferralCode.code, so any of
 * them is enough to let the visitor through.
 */
class ReferralRegistrationGate
{
    /** Matches the cap TrackReferralCode applies before storing a code. */
    private const MAX_CODE_LENGTH = 64;

    public function __construct(
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function isActive(): bool
    {
        return (bool) config('app.referral.only_registration', false);
    }

    public function cookieName(): string
    {
        return (string) config('app.referral.cookie_name', 'fp_referral');
    }

    /**
     * Does the registration form need to ask for a code? The email is unknown
     * while the page renders, so a workspace invitee still sees the field —
     * requiresCodeFor() is what decides whether their submission needs one.
     */
    public function requiresCodeInput(?Request $request = null): bool
    {
        return $this->isActive() && $this->storedCode($request) === null;
    }

    public function requiresCodeFor(?string $email, ?Request $request = null): bool
    {
        return $this->requiresCodeInput($request) && ! $this->hasPendingInvitation($email);
    }

    /**
     * The valid code this visitor already carries, if any.
     */
    public function storedCode(?Request $request = null): ?string
    {
        $request ??= request();

        $candidates = [
            session(SessionConstants::REFERRAL_CODE),
            $request->cookie($this->cookieName()),
            $this->partnerAttributionService->cookieCode($request),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isValidCode($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function isValidCode(?string $code): bool
    {
        if (! is_string($code) || $code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return false;
        }

        return ReferralCode::where('code', $code)->exists();
    }

    public function hasPendingInvitation(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return Invitation::where('email', strtolower(trim($email)))
            ->where('status', InvitationStatus::PENDING->value)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Remembers a code beyond the session, so a visitor who clicks a referral
     * link today can still register from it next week.
     */
    public function rememberCode(string $code): void
    {
        Cookie::queue(cookie(
            name: $this->cookieName(),
            value: $code,
            minutes: (int) config('app.referral.cookie_lifetime_days', 365) * 24 * 60,
            path: '/',
            domain: null,
            secure: request()->isSecure() ? true : null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
