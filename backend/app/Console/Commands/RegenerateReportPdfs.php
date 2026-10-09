<?php

namespace App\Console\Commands;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Console\Command;

/**
 * One-off after the report split: every unlocked report's stored PDF is the
 * old combined report. Rewrites it as the business PDF and adds the
 * developer PDF. Safe to re-run -- it only ever regenerates.
 */
class RegenerateReportPdfs extends Command
{
    protected $signature = 'app:regenerate-report-pdfs {--dry-run : List what would be regenerated and change nothing}';

    protected $description = 'Regenerate the business and developer PDFs of every unlocked audit report';

    public function handle(AuditReportService $reports): int
    {
        $query = AuditReport::query()
            ->whereNotNull('unlocked_at')
            ->with('auditRequest');

        $done = 0;

        $query->chunkById(50, function ($chunk) use ($reports, &$done): void {
            foreach ($chunk as $report) {
                // A held report's PDF is written when its reviewer publishes.
                if ($report->auditRequest === null || $report->auditRequest->isHeldForExpertReview()) {
                    continue;
                }

                if (! $this->option('dry-run')) {
                    $reports->regeneratePdf($report);
                }

                $this->line($report->uuid);
                $done++;
            }
        });

        $this->info(($this->option('dry-run') ? 'Would regenerate ' : 'Regenerated ').$done.' report(s).');

        return self::SUCCESS;
    }
}
