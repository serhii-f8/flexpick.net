<?php

namespace App\Mail\Concerns;

use App\Constants\ReportVariant;
use App\Models\AuditReport;
use Illuminate\Mail\Mailables\Attachment;

/**
 * Attaches whichever of a report's two PDFs exist: the business report and
 * the developer report. Each is checked on its own -- a report unlocked
 * before the split has only the first.
 *
 * @property AuditReport $report
 */
trait AttachesReportPdfs
{
    /** @return array<int, Attachment> */
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
