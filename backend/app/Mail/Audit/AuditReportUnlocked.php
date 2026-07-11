<?php

namespace App\Mail\Audit;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditReportUnlocked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $reportUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your full codebase report is unlocked'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.unlocked',
        );
    }
}
