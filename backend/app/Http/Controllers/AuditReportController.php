<?php

namespace App\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use App\Services\AuditGuestAccountService;
use App\Services\AuditReport\AuditBenchmarkService;
use App\Services\AuditReport\AuditDeltaService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AuditReportController extends Controller
{
    public function show(AuditReport $auditReport, AuditBenchmarkService $benchmark)
    {
        // A report held for expert review still carries the raw, un-reviewed
        // AI payload -- exactly what the review stage exists to catch before
        // a customer sees it. The blade already hides the link, but a signed
        // URL bypasses that, so this is the real gate.
        abort_if($auditReport->auditRequest->isHeldForExpertReview(), 403);

        if ($auditReport->auditRequest->source !== 'dashboard') {
            app(AuditFunnelRecorder::class)->record(
                AuditFunnelRecorder::STAGE_REPORT_VIEWED,
                $auditReport->auditRequest,
                ['unlocked' => $auditReport->unlocked_at !== null],
            );
        }

        return view('reports.audit-web', [
            'report' => $auditReport,
            'unlocked' => $auditReport->unlocked_at !== null,
            'isSample' => false,
            'percentile' => $benchmark->percentileFor((int) data_get($auditReport->payload, 'scores.overall', 0), $auditReport->scoring_version),
            'unlockUrl' => URL::temporarySignedRoute(
                'reports.unlock',
                now()->addDays((int) config('audit.report_link_days')),
                ['auditReport' => $auditReport->uuid],
            ),
            'deltas' => app(AuditDeltaService::class)->deltasFor($auditReport),
        ]);
    }

    public function sample()
    {
        $path = resource_path('data/sample-audit-report.json');

        abort_unless(is_file($path), 404);

        $fixture = json_decode(file_get_contents($path), true);

        abort_if(! is_array($fixture), 404);

        $request = new AuditRequest(['repo_url' => $fixture['repo_url'], 'metrics' => $fixture['metrics'] ?? null]);
        $report = new AuditReport(['payload' => $fixture['payload']]);
        $report->setRelation('auditRequest', $request);
        $report->created_at = now();

        return view('reports.audit-web', [
            'report' => $report,
            'unlocked' => true,
            'isSample' => true,
            'percentile' => $fixture['percentile'],
            'unlockUrl' => null,
            // The sample exists to show every section a report can carry, and
            // the re-audit trend is one of them. Stored as an offset so the
            // "previous audit" date never goes stale.
            'deltas' => isset($fixture['deltas'])
                ? [
                    'previous_at' => now()->subDays((int) ($fixture['previous_audit_days_ago'] ?? 30)),
                    'deltas' => $fixture['deltas'],
                ]
                : null,
        ]);
    }

    public function download(AuditReport $auditReport)
    {
        // Operators triage other people's runs from the admin panel, so
        // ownership cannot be the only key here -- it would 403 an admin on
        // every report except the one they never need to open. The
        // expert-review gate below still applies to everyone.
        abort_unless(
            $auditReport->user_id === auth()->id() || auth()->user()?->isAdmin(),
            403,
        );
        abort_if($auditReport->auditRequest->isHeldForExpertReview(), 403);
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
