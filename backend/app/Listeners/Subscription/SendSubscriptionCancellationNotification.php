<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\SubscriptionCancelled;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSubscriptionCancellationNotification implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
    ) {}

    public function handle(SubscriptionCancelled $event): void
    {
        $this->mailer->send(
            new \App\Mail\Subscription\SubscriptionCancelled($event->subscription),
            $event->subscription->user->email,
        );
    }
}
