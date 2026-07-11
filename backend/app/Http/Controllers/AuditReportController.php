<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use Illuminate\Support\Facades\Storage;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport)
    {
        return view('reports.audit', ['report' => $auditReport]);
    }

    public function download(AuditReport $auditReport)
    {
        abort_unless($auditReport->user_id === auth()->id(), 403);
        abort_if($auditReport->pdf_path === null, 404);

        return Storage::disk('local')->download($auditReport->pdf_path, 'codebase-health-report.pdf');
    }
}
