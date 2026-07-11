<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
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
            Storage::disk('local')->delete($existing->pdf_path);
            $existing->delete();
        }

        $report = new AuditReport([
            'audit_request_id' => $auditRequest->id,
            'user_id' => User::where('email', $auditRequest->email)->value('id'),
            'payload' => $payload,
            'pdf_path' => 'pending',
        ]);
        $report->save();

        $pdfPath = config('audit.reports_dir').'/'.$report->uuid.'.pdf';
        $pdf = Pdf::loadView('reports.audit', ['report' => $report]);
        Storage::disk('local')->put($pdfPath, $pdf->output());

        $report->update(['pdf_path' => $pdfPath]);
        $auditRequest->update(['status' => AuditRequestStatus::REPORT_READY->value]);

        return $report;
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
}
