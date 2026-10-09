<?php

namespace App\Mail\Audit;

use App\Mail\Concerns\AttachesReportPdfs;
use App\Mail\Concerns\TracksAuditEmailLog;
use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditReportReady extends Mailable implements ShouldQueue
{
    use AttachesReportPdfs, Queueable, SerializesModels, TracksAuditEmailLog;

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
}
