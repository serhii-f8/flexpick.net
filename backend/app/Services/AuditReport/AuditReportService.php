<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditReportUnlocked;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditMail\AuditMailer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AuditReportService
{
    public function __construct(
        private AuditFunnelRecorder $funnel,
        private AuditDeltaService $deltaService,
        private AuditMailer $auditMailer,
    ) {}

    public function create(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport
    {
        $wasUnlocked = false;
        $unlockOrderId = null;

        if ($existing = $auditRequest->report()->first()) {
            $wasUnlocked = $existing->unlocked_at !== null;
            $unlockOrderId = $existing->unlock_order_id;

            if ($existing->pdf_path !== null) {
                Storage::disk('local')->delete($existing->pdf_path);
            }
            $existing->delete();
        }

        $unlocked = $auditRequest->source === 'dashboard' || $wasUnlocked || $auditRequest->prepaid;

        $report = new AuditReport([
            'audit_request_id' => $auditRequest->id,
            'user_id' => $auditRequest->user_id ?? User::where('email', $auditRequest->email)->value('id'),
            'payload' => $payload,
            'pdf_path' => null,
            'unlocked_at' => $unlocked ? now() : null,
            'unlock_order_id' => $wasUnlocked ? $unlockOrderId : null,
            'scoring_version' => $scoringVersion,
            'payload_schema_version' => ReportPayload::VERSION,
        ]);
        $report->save();

        if ($unlocked) {
            $this->generatePdf($report);
        }

        $auditRequest->update(['status' => AuditRequestStatus::REPORT_READY->value]);

        return $report;
    }

    public function createAndDeliver(AuditRequest $auditRequest, array $payload, int $scoringVersion): AuditReport
    {
        $report = $this->create($auditRequest, $payload, $scoringVersion);

        if ($auditRequest->tier === AuditTier::EXPERT) {
            $auditRequest->update(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        } else {
            $this->send($report);
        }

        return $report;
    }

    public function unlock(AuditReport $report, ?Order $order = null): void
    {
        if ($report->unlocked_at !== null) {
            return;
        }

        $report->update(['unlocked_at' => now(), 'unlock_order_id' => $order?->id]);
        $this->generatePdf($report);

        if ($order !== null) {
            $this->funnel->record(AuditFunnelRecorder::STAGE_UNLOCK_PAID, $report->auditRequest);
        }

        $this->auditMailer->send(new AuditReportUnlocked($report, $this->signedUrl($report)), $report->auditRequest->email, $report->auditRequest);
    }

    public function send(AuditReport $report): void
    {
        $this->auditMailer->send(new AuditReportReady($report, $this->signedUrl($report), $this->deltaService->deltasFor($report)), $report->auditRequest->email, $report->auditRequest);

        $report->auditRequest->update(['status' => AuditRequestStatus::SENT->value]);

        if ($report->auditRequest->source !== 'dashboard') {
            $this->funnel->record(AuditFunnelRecorder::STAGE_REPORT_SENT, $report->auditRequest);
        }
    }

    public function publish(AuditReport $report): void
    {
        // @phpstan-ignore-next-line property.notFound (status is a real column on AuditRequest; Larastan can't see it through the auditRequest relation's generic Model return type)
        if ($report->auditRequest->status !== AuditRequestStatus::EXPERT_REVIEW->value) {
            return;
        }

        $payload = $report->payload;

        if (trim((string) ($payload['expert_review']['expert_summary'] ?? '')) === '') {
            throw new \InvalidArgumentException('Cannot publish a report without an expert summary.');
        }

        if (auth()->user() === null) {
            throw new \LogicException('AuditReportService::publish() requires an authenticated user.');
        }

        $payload['expert_review']['reviewed_by'] = auth()->user()->name;
        $payload['expert_review']['reviewed_at'] = now()->toIso8601String();

        $validated = ReportPayload::validate($payload, ReportPayload::VERSION);

        $report->update(['payload' => $validated, 'payload_schema_version' => ReportPayload::VERSION]);
        $this->regeneratePdf($report);
        $this->send($report);
    }

    public function regeneratePdf(AuditReport $report): void
    {
        $this->generatePdf($report);
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
