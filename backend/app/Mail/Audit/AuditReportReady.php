<?php

namespace App\Mail\Audit;

use App\Constants\ReportVariant;
use App\Mail\Concerns\TracksAuditEmailLog;
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
    use Queueable, SerializesModels, TracksAuditEmailLog;

    public function __construct(
        public AuditReport $report,
        public string $signedUrl,
        public ?array $deltas = null,
        public ?array $groupDeltas = null,
        public ?string $technicalUrl = null,
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
        $files = [
            [ReportVariant::BUSINESS, $this->report->pdf_path],
            [ReportVariant::TECHNICAL, $this->report->technical_pdf_path],
        ];

        $attachments = [];
        foreach ($files as [$variant, $path]) {
            if ($path !== null) {
                $attachments[] = Attachment::fromStorageDisk('local', $path)
                    ->as($variant->pdfFilename())
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
}
