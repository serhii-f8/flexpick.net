<?php

namespace App\Mail\CashPayments;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerNewPendingOrder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('A customer is waiting for you to confirm a cash payment'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cash-payments.partner-new-pending-order');
    }
}
