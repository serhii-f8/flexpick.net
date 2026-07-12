<?php

namespace App\Services;

use App\Models\AuditRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

class AuditGuestAccountService
{
    public function __construct(
        private UserService $userService,
    ) {}

    /**
     * Resolve the user acting on a verified audit request's behalf.
     * Creates + logs in a new account for the audit email when none exists.
     * Returns null when an existing account owns the email (visitor must log in).
     */
    public function resolveUser(AuditRequest $auditRequest): ?User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        if ($this->userService->findByEmail($auditRequest->email) !== null) {
            return null;
        }

        $user = $this->userService->createUser([
            'name' => $auditRequest->name,
            'email' => $auditRequest->email,
        ]);

        $user->email_verified_at = now();
        $user->save();

        event(new Registered($user));

        auth()->login($user);

        return $user;
    }
}
