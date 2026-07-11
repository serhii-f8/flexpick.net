<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditReportUnlocked;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Order;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AuditReportService
{
    public function create(AuditRequest $auditRequest, array $payload): AuditReport
    {
        if ($existing = $auditRequest->report()->first()) {
            if ($existing->pdf_path !== null) {
                Storage::disk('local')->delete($existing->pdf_path);
            }
            $existing->delete();
        }

        $unlocked = $auditRequest->source === 'dashboard';

        $report = new AuditReport([
            'audit_request_id' => $auditRequest->id,
            'user_id' => $auditRequest->user_id ?? User::where('email', $auditRequest->email)->value('id'),
            'payload' => $payload,
            'pdf_path' => null,
            'unlocked_at' => $unlocked ? now() : null,
        ]);
        $report->save();

        if ($unlocked) {
            $this->generatePdf($report);
        }

        $auditRequest->update(['status' => AuditRequestStatus::REPORT_READY->value]);

        return $report;
    }

    public function unlock(AuditReport $report, ?Order $order = null): void
    {
        if ($report->unlocked_at !== null) {
            return;
        }

        $report->update(['unlocked_at' => now(), 'unlock_order_id' => $order?->id]);
        $this->generatePdf($report);

        Mail::to($report->auditRequest->email)
            ->send(new AuditReportUnlocked($report, $this->signedUrl($report)));
    }

    public function send(AuditReport $report): void
    {
        Mail::to($report->auditRequest->email)
            ->send(new AuditReportReady($report, $this->signedUrl($report)));

        $report->auditRequest->update(['status' => AuditRequestStatus::SENT->value]);
    }

    public function signedUrl(AuditReport $report): string
    {
        return URL::temporarySignedRoute(
            'reports.view',
            now()->addDays((int) config('audit.report_link_days')),
            ['auditReport' => $report->uuid],
        );
    }

    private function generatePdf(AuditReport $report): void
    {
        $pdfPath = config('audit.reports_dir').'/'.$report->uuid.'.pdf';
        $pdf = Pdf::loadView('reports.audit', ['report' => $report]);
        Storage::disk('local')->put($pdfPath, $pdf->output());

        $report->update(['pdf_path' => $pdfPath]);
    }
}
