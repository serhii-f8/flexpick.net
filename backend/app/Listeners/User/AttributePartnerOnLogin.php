<?php

namespace App\Listeners\User;

use App\Constants\PartnerAttributionSource;
use App\Services\PartnerAttributionService;
use Illuminate\Auth\Events\Login;

class AttributePartnerOnLogin
{
    public function __construct(
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Login $event): void
    {
        $this->partnerAttributionService->attribute($event->user, PartnerAttributionSource::LOGIN);
    }
}
