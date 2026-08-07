<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\InvoicePaymentFailed;
use App\Services\Mail\RenderSafeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoicePaymentFailedNotification implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
    ) {}

    public function handle(InvoicePaymentFailed $event): void
    {
        $this->mailer->send(
            new \App\Mail\Subscription\InvoicePaymentFailed($event->subscription),
            $event->subscription->user->email,
        );
    }
}
