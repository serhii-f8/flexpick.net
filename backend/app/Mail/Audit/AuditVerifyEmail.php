<?php

namespace App\Mail\Audit;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditVerifyEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditRequest $auditRequest,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Confirm your email to start your free audit'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.verify',
        );
    }
}
