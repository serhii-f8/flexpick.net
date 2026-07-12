<?php

namespace App\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use App\Services\AuditGuestAccountService;
use App\Services\AuditReport\AuditBenchmarkService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        app(AuditFunnelRecorder::class)->record(
            AuditFunnelRecorder::STAGE_REPORT_VIEWED,
            $auditReport->auditRequest,
            ['unlocked' => $auditReport->unlocked_at !== null],
        );

        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => $benchmark->percentileFor((int) data_get($auditReport->payload, 'scores.overall', 0)),
            'unlockUrl' => URL::temporarySignedRoute(
                'reports.unlock',
                now()->addDays((int) config('audit.report_link_days')),
                ['auditReport' => $auditReport->uuid],
            ),
        ]);
    }

    public function sample()
    {
        $path = resource_path('data/sample-audit-report.json');

        abort_unless(is_file($path), 404);

        $fixture = json_decode(file_get_contents($path), true);

        abort_if(! is_array($fixture), 404);

        $request = new AuditRequest(['repo_url' => $fixture['repo_url']]);
        $report = new AuditReport(['payload' => $fixture['payload']]);
        $report->setRelation('auditRequest', $request);
        $report->created_at = now();

        return view('reports.audit-web', [
            'report' => $report,
            'unlocked' => true,
            'isSample' => true,
            'percentile' => $fixture['percentile'],
            'unlockUrl' => null,
        ]);
    }

    public function download(AuditReport $auditReport)
    {
        abort_unless($auditReport->user_id === auth()->id(), 403);
        abort_if($auditReport->pdf_path === null, 404);

        return Storage::disk('local')->download($auditReport->pdf_path, 'codebase-health-report.pdf');
    }

    public function unlock(AuditReport $auditReport, AuditGuestAccountService $guestAccounts)
    {
        $user = $guestAccounts->resolveUser($auditReport->auditRequest);

        if ($user === null) {
            return redirect()->guest(route('login'))->with('status', __(
                'An account already exists for :email — log in to unlock this report.',
                ['email' => $auditReport->auditRequest->email],
            ));
        }

        if ($auditReport->user_id === null && strtolower($auditReport->auditRequest->email) === strtolower($user->email)) {
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

        app(AuditFunnelRecorder::class)->record(AuditFunnelRecorder::STAGE_UNLOCK_STARTED, $auditReport->auditRequest);

        return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
    }
}
