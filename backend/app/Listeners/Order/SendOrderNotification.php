<?php

namespace App\Listeners\Order;

use App\Events\Order\Ordered;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderNotification implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
    ) {}

    public function handle(Ordered $event): void
    {
        $this->mailer->send(
            new \App\Mail\Order\Ordered($event->order),
            $event->order->user->email,
        );
    }
}
