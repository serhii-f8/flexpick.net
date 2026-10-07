<?php

namespace App\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\ReferralConstants;
use App\Mail\Audit\AuditAccountReady;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\AuditMail\AuditMailer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Confirming the landing form's email is the whole sign-up. The confirmation
 * link proves the visitor owns the inbox, so it creates their account
 * (already verified), attributes it to whoever invited them -- from the code
 * stored on the request, since the browser they confirm from may never have
 * seen the referral link -- and gives it a workspace, which claims the audit
 * (ClaimAuditRequestsForTenant). The account starts with a random password;
 * a one-use, signed link lets them pick their own and lands them on Git
 * Connections to grant access to a private repository.
 */
class AuditAccountProvisioner
{
    /** Present while the account still has the random password it was created with. */
    public const PASSWORD_PENDING_PARAM = 'audit_password_setup_pending';

    public function __construct(
        private UserService $userService,
        private PartnerAttributionService $attributionService,
        private TenantCreationService $tenantCreationService,
        private AuditMailer $auditMailer,
    ) {}

    /** The new account, or null when one already exists for the email (it is never touched). */
    public function provision(AuditRequest $auditRequest): ?User
    {
        if ($this->userService->findByEmail($auditRequest->email) !== null) {
            return null;
        }

        $code = $auditRequest->meta['referral_code'] ?? null;
        $code = is_string($code) && $code !== '' ? $code : null;

        try {
            $user = DB::transaction(function () use ($auditRequest, $code): User {
                $user = $this->userService->createUser(array_filter([
                    'name' => $auditRequest->name,
                    'email' => $auditRequest->email,
                    // Tracks the personal referral exactly as a ?rc= sign-up would.
                    ReferralConstants::REGISTRATION_CODE_FIELD => $code,
                ]));

                $user->forceFill(['email_verified_at' => now()])->save();

                if ($code !== null) {
                    $this->attributionService->attributeToCode($user, $code, PartnerAttributionSource::REGISTRATION);
                    $this->attributionService->refreshCookieFromDatabase($user);
                }

                $this->tenantCreationService->createTenant($user);
                $auditRequest->update(['user_id' => $user->id]);

                UserParameter::create(['user_id' => $user->id, 'name' => self::PASSWORD_PENDING_PARAM, 'value' => '1']);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A second click raced the first one to create the same account.
            return null;
        }

        event(new Registered($user));

        $this->auditMailer->send(new AuditAccountReady($auditRequest, $this->setPasswordUrl($user)), $user->email, $auditRequest);

        return $user;
    }

    public function setPasswordUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'audit-account.set-password',
            now()->addDays((int) config('audit.set_password_link_days', 7)),
            ['user' => $user->uuid],
        );
    }

    public function awaitsPassword(User $user): bool
    {
        return UserParameter::query()
            ->where('user_id', $user->id)
            ->where('name', self::PASSWORD_PENDING_PARAM)
            ->exists();
    }

    public function setPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => Hash::make($password)])->save();

            UserParameter::query()
                ->where('user_id', $user->id)
                ->where('name', self::PASSWORD_PENDING_PARAM)
                ->delete();
        });
    }

    /** The workspace the account was created with -- where its audit and Git Connections live. */
    public function workspaceFor(User $user): ?Tenant
    {
        /** @var Tenant|null */
        return $user->tenants()->oldest('tenants.id')->first();
    }
}
