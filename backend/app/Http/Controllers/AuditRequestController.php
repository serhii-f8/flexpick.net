<?php

namespace App\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Http\Requests\StoreAuditRequestRequest;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Listeners\Order\HandleAuditTierOrder;
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

    /**
     * "Run this audit now" from the quota-exhausted email: send the visitor to
     * checkout for the tier they already asked for.
     *
     * The product is resolved from the tier catalog, exactly as the dashboard's
     * purchase flow does. It must not be the single `audit.unlock_product_slug`
     * product: that slug is retired (config('pricing.retired.one_time')) and
     * deactivated on every seed, and checkout only resolves active products, so
     * every one of these links would 404 at the till.
     */
    public function purchaseRun(AuditRequest $auditRequest, AuditGuestAccountService $guestAccounts)
    {
        abort_unless($auditRequest->status === AuditRequestStatus::AWAITING_PAYMENT->value, 404);

        $slug = collect((array) config('pricing.tiers'))
            ->search(fn (array $definition): bool => ($definition['tier'] ?? null) === $auditRequest->tier->value);

        // Every tier is priced today, so this is purely defensive — but a link
        // that cannot be honoured must not create an account and then 500 on
        // its way to a nonexistent product.
        if ($slug === false) {
            abort(404);
        }

        $user = $guestAccounts->resolveUser($auditRequest);

        if ($user === null) {
            return redirect()->guest(route('login'))->with('status', __(
                'An account already exists for :email — log in to pay for this audit.',
                ['email' => $auditRequest->email],
            ));
        }

        // HandleAuditTierOrder::intentRequestFor() matches this uuid against an
        // awaiting_payment request at the ordered tier, which is precisely this
        // request, and runs it once the order completes.
        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        return redirect()->route('buy.product', ['productSlug' => $slug]);
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
            'awaiting_payment' => __('Payment needed to continue — check your email for options'),
            'expert_review' => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
            default => __('Processing'),
        };
    }
}
