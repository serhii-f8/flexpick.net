<?php

namespace App\Mail\Audit;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAuditRequestAdminNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New audit request: :email', ['email' => $this->auditRequest->email]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.admin-new-request',
        );
    }
}
