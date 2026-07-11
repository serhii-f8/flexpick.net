<?php

namespace App\Mail\Audit;

use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditRepoAccessNeeded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('One more step for your codebase audit'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.access-needed',
        );
    }
}
