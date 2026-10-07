<?php

namespace App\Mail\Audit;

use App\Mail\Concerns\TracksAuditEmailLog;
use App\Models\AuditRequest;
use App\Models\Currency;
use App\Models\Order;
use App\Services\PartnerContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditAwaitingPartnerApproval extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, TracksAuditEmailLog;

    public function __construct(
        public AuditRequest $auditRequest,
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your audit is booked — waiting for payment confirmation'),
        );
    }

    public function content(): Content
    {
        $code = $this->auditRequest->meta['referral_code'] ?? null;
        /** @var Currency|null $currency */
        $currency = $this->order->currency;

        return new Content(
            view: 'emails.audit.awaiting-partner-approval',
            with: [
                'partner' => app(PartnerContactResolver::class)->forCode(is_string($code) ? $code : null),
                'amount' => money($this->order->total_amount_after_discount ?: $this->order->total_amount, $currency?->code ?? config('app.default_currency')),
            ],
        );
    }
}
