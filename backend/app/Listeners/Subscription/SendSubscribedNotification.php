<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\Subscribed;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSubscribedNotification implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
    ) {}

    public function handle(Subscribed $event): void
    {
        $this->mailer->send(
            new \App\Mail\Subscription\Subscribed($event->subscription),
            $event->subscription->user->email,
        );
    }
}
