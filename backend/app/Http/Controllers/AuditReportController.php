<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Storage;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport)
    {
        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => null,
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
}
