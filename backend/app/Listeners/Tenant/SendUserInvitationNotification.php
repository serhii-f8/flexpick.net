<?php

namespace App\Listeners\Tenant;

use App\Events\Tenant\UserInvitedToTenant;
use App\Mail\Tenant\UserInvitation;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserInvitationNotification implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
    ) {}

    public function handle(UserInvitedToTenant $event): void
    {
        $this->mailer->send(
            new UserInvitation($event->invitation),
            $event->invitation->email,
        );
    }
}
