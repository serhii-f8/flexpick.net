<?php

namespace App\Mail\Audit;

use App\Constants\ReferralConstants;
use App\Mail\Concerns\TracksAuditEmailLog;
use App\Models\AuditRequest;
use App\Services\PartnerContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditQuotaExhausted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, TracksAuditEmailLog;

    public function __construct(
        public AuditRequest $auditRequest,
        public string $purchaseUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your audit needs payment — here\'s how to continue'),
        );
    }

    public function content(): Content
    {
        // A visitor a partner referred pays the partner's price, so the catalog's
        // must not be quoted to them; their sign-up link carries the partner's code.
        $code = $this->auditRequest->meta['referral_code'] ?? null;
        $referred = app(PartnerContactResolver::class)->forCode(is_string($code) ? $code : null) !== null;

        return new Content(
            view: 'emails.audit.quota-exhausted',
            with: [
                'quoteCatalogPrices' => ! $referred,
                'registerUrl' => $referred ? route('register', [ReferralConstants::HTTP_PARAM_REFERRAL_CODE => $code]) : route('register'),
            ],
        );
    }
}
