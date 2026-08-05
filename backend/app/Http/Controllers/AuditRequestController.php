<?php

namespace App\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Http\Requests\StoreAuditRequestRequest;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use App\Services\AuditGuestAccountService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

class AuditRequestController extends Controller
{
    public function store(
        StoreAuditRequestRequest $request,
        AuditRequestService $auditRequestService,
    ): JsonResponse {
        $auditRequest = $auditRequestService->submit($request->validated(), [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return response()->json(['id' => $auditRequest->uuid], 201);
    }

    public function verify(AuditRequest $auditRequest, AuditFunnelRecorder $funnel, AuditRequestService $auditRequestService)
    {
        if ($auditRequest->email_verified_at === null) {
            $auditRequest->update(['email_verified_at' => now()]);
            $funnel->record(AuditFunnelRecorder::STAGE_VERIFIED, $auditRequest);
            RouteVerifiedAuditRequest::dispatch($auditRequest);
        }

        return redirect($auditRequestService->statusUrl($auditRequest));
    }

    public function status(AuditRequest $auditRequest)
    {
        return view('audit.status', [
            'auditRequest' => $auditRequest,
            'label' => $this->label($auditRequest->status),
            'pollUrl' => URL::signedRoute('audit-requests.status.json', ['auditRequest' => $auditRequest->uuid]),
        ]);
    }

    public function statusJson(AuditRequest $auditRequest, AuditReportService $reportService): JsonResponse
    {
        $report = $auditRequest->report;
        $ready = $report !== null && in_array($auditRequest->status, [
            AuditRequestStatus::REPORT_READY->value,
            AuditRequestStatus::SENT->value,
        ], true);

        return response()->json([
            'status' => $auditRequest->status,
            'label' => $this->label($auditRequest->status),
            'done' => $ready,
            'failed' => $auditRequest->status === AuditRequestStatus::FAILED->value,
            'report_url' => $ready ? $reportService->signedUrl($report) : null,
        ]);
    }

    public function purchaseRun(AuditRequest $auditRequest, AuditGuestAccountService $guestAccounts)
    {
        abort_unless($auditRequest->status === AuditRequestStatus::AWAITING_PAYMENT->value, 404);

        $user = $guestAccounts->resolveUser($auditRequest);

        if ($user === null) {
            return redirect()->guest(route('login'))->with('status', __(
                'An account already exists for :email — log in to pay for this audit.',
                ['email' => $auditRequest->email],
            ));
        }

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditUnlockOrder::RUN_INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        return redirect()->route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]);
    }

    private function label(string $status): string
    {
        return match ($status) {
            'pending_verification' => __('Waiting for you to confirm your email'),
            'new' => __('Request received'),
            'queued' => __('Queued for analysis'),
            'analyzing' => __('Analyzing your repository'),
            'report_ready', 'sent' => __('Your report is ready'),
            'failed' => __('The analysis hit a snag — an engineer is taking a look'),
            'needs_followup', 'awaiting_access' => __('We need access to your repository — check your email'),
            'awaiting_payment' => __('Your free audits are used up — check your email for options'),
            'expert_review' => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
            default => __('Processing'),
        };
    }
}
