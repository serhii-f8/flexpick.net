<?php

namespace App\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\ReferralConstants;
use App\Constants\SessionConstants;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(
        private ReferralService $referralService,
        private PartnerAttributionService $partnerAttributionService,
        private ReferralRegistrationGate $referralRegistrationGate,
    ) {}

    public function createUser(array $data, bool $dispatchRegisterEvent = false): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => isset($data['password']) ? Hash::make($data['password']) : Hash::make(Str::random(32)),
        ]);

        $referralCode = $this->resolveReferralCode($data);

        if ($referralCode !== null) {
            $this->referralService->trackReferral($user, $referralCode);
            session()->forget(SessionConstants::REFERRAL_CODE);
        }

        $this->partnerAttributionService->attribute($user, PartnerAttributionSource::REGISTRATION);
        $this->partnerAttributionService->refreshCookieFromDatabase($user);

        if ($dispatchRegisterEvent) {
            event(new Registered($user));
        }

        return $user;
    }

    /**
     * A code typed into the invite-only registration form wins; otherwise the
     * session copy, then the referral cookie (checked by the gate), so a link
     * clicked weeks ago still credits its owner.
     */
    private function resolveReferralCode(array $data): ?string
    {
        $typed = $data[ReferralConstants::REGISTRATION_CODE_FIELD] ?? null;

        if (is_string($typed) && $this->referralRegistrationGate->isValidCode($typed)) {
            return $typed;
        }

        $fromSession = session(SessionConstants::REFERRAL_CODE);

        if (is_string($fromSession) && $fromSession !== '') {
            return $fromSession;
        }

        return $this->referralRegistrationGate->storedCode();
    }

    public function updateUserLastSeen(User $user)
    {
        $user->last_seen_at = now();
        $user->save();
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', strtolower($email))->first();
    }

    public function anonymize(User $user): void
    {
        $id = $user->id;

        $user->name = "Anonymized User $id";
        $user->email = "anonymized_$id@example.com";
        $user->public_name = "Anonymized User $id";
        $user->phone_number = null;
        $user->email_verified_at = null;
        $user->phone_number_verified_at = null;
        $user->password = Hash::make(Str::random(40));
        $user->save();

        $user->address()?->delete();
    }
}
