<?php

namespace App\Listeners\User;

use App\Services\PartnerAttributionService;
use Illuminate\Auth\Events\Logout;

/**
 * Mirrors AttributePartnerOnLogin: the fp_rc cookie is re-issued from the
 * database on every login, so it must also be forgotten on every logout.
 * Without this, a partner's cookie survives on a shared browser and
 * mis-attributes the next person who signs in on it (spec §3.4).
 */
class ForgetPartnerCookieOnLogout
{
    public function __construct(
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Logout $event): void
    {
        $this->partnerAttributionService->forgetCookie();
    }
}
