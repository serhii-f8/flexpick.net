<?php

namespace App\Mail\Audit;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditReportReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $signedUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your codebase health report is ready'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.report-ready',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->report->pdf_path === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->report->pdf_path)
                ->as('codebase-health-report.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
