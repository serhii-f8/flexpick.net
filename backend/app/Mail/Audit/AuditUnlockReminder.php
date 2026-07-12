<?php

namespace App\Mail\Audit;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditUnlockReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $unlockUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your full codebase report is one click away'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.unlock-reminder',
        );
    }
}
