<?php

namespace App\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditBenchmarkService;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Storage;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => $benchmark->percentileFor((int) data_get($auditReport->payload, 'scores.overall', 0)),
        ]);
    }

    public function sample()
    {
        $fixture = json_decode(file_get_contents(resource_path('data/sample-audit-report.json')), true);

        $request = new AuditRequest(['repo_url' => $fixture['repo_url']]);
        $report = new AuditReport(['payload' => $fixture['payload']]);
        $report->setRelation('auditRequest', $request);
        $report->created_at = now();

        return view('reports.audit-web', [
            'report' => $report,
            'unlocked' => true,
            'isSample' => true,
            'percentile' => $fixture['percentile'],
        ]);
    }

    public function download(AuditReport $auditReport)
    {
        abort_unless($auditReport->user_id === auth()->id(), 403);
        abort_if($auditReport->pdf_path === null, 404);

        return Storage::disk('local')->download($auditReport->pdf_path, 'codebase-health-report.pdf');
    }

    public function unlock(AuditReport $auditReport)
    {
        $user = auth()->user();

        if ($auditReport->user_id === null && $auditReport->auditRequest->email === $user->email) {
            $auditReport->user_id = $user->id;
            $auditReport->save();
        }

        abort_unless($auditReport->user_id === $user->id, 403);

        if ($auditReport->unlocked_at !== null) {
            return redirect(app(AuditReportService::class)->signedUrl($auditReport));
        }

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::INTENT_PARAM],
            ['value' => $auditReport->uuid],
        );

        return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
    }
}
