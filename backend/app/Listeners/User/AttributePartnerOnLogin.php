<?php

namespace App\Listeners\User;

use App\Constants\PartnerAttributionSource;
use App\Models\User;
use App\Services\PartnerAttributionService;
use Illuminate\Auth\Events\Login;

class AttributePartnerOnLogin
{
    public function __construct(
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $this->partnerAttributionService->attribute($user, PartnerAttributionSource::LOGIN);
        $this->partnerAttributionService->refreshCookieFromDatabase($user);
    }
}
