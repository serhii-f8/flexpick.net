<?php

namespace App\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Http\Requests\StoreAuditRequestRequest;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\TenantParameter;
use App\Services\AuditAccountProvisioner;
use App\Services\AuditGuestAccountService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditRequestService;
use App\Services\PrimaryTenantResolver;
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

    public function verify(AuditRequest $auditRequest, AuditFunnelRecorder $funnel, AuditRequestService $auditRequestService, AuditAccountProvisioner $accounts)
    {
        if ($auditRequest->email_verified_at === null) {
            $auditRequest->update(['email_verified_at' => now()]);
            $funnel->record(AuditFunnelRecorder::STAGE_VERIFIED, $auditRequest);

            // Confirming is the sign-up: the account (and its workspace, which
            // claims this request) exists before routing, so a referred
            // request's cash order has a buyer and a workspace to land on.
            $user = $accounts->provision($auditRequest);

            if ($user !== null && auth()->guest()) {
                auth()->login($user);
                request()->session()->regenerate();
            }

            RouteVerifiedAuditRequest::dispatch($auditRequest->fresh());
        }

        return redirect($auditRequestService->statusUrl($auditRequest));
    }

    public function status(AuditRequest $auditRequest, AuditAccountProvisioner $accounts)
    {
        $user = auth()->user();
        $owner = $user !== null && (int) $auditRequest->user_id === (int) $user->id;
        $workspace = $owner ? $accounts->workspaceFor($user) : null;

        return view('audit.status', [
            'auditRequest' => $auditRequest,
            'label' => $this->label($auditRequest),
            // Only the signed-in owner gets account links; the status URL itself is shareable.
            'setPasswordUrl' => $owner && $accounts->awaitsPassword($user) ? $accounts->setPasswordUrl($user) : null,
            'gitConnectionsUrl' => $workspace !== null ? GitConnections::getUrl(panel: 'dashboard', tenant: $workspace) : null,
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
            'label' => $this->label($auditRequest),
            'done' => $ready,
            'failed' => $auditRequest->status === AuditRequestStatus::FAILED->value,
            // Refunded terminal closes: nothing more will happen to this
            // request, so the page stops polling and leaves the label up.
            'closed' => in_array($auditRequest->status, [
                AuditRequestStatus::AWAITING_CREDIT->value,
                AuditRequestStatus::NOT_ANALYZABLE->value,
            ], true),
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
    public function purchaseRun(AuditRequest $auditRequest, AuditGuestAccountService $guestAccounts, PrimaryTenantResolver $primaryTenants)
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
        // request, and runs it once the order completes. A fresh guest account
        // has no workspace yet: checkout creates one, ClaimAuditRequestsForTenant
        // stamps this request with it, and the listener's awaiting-payment
        // fallback finds it -- so the intent is only written when there is
        // already a workspace to key it on. When there is one, checkout is
        // pinned to it (`tenant`) so the order lands where the intent is.
        $tenant = $primaryTenants->resolve($user);

        if ($tenant === null) {
            return redirect()->route('buy.product', ['productSlug' => $slug]);
        }

        TenantParameter::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        return redirect()->route('buy.product', ['productSlug' => $slug, 'tenant' => $tenant->uuid]);
    }

    private function label(AuditRequest $auditRequest): string
    {
        return match ($auditRequest->status) {
            'pending_verification' => __('Waiting for you to confirm your email'),
            'new' => __('Request received'),
            'queued' => __('Queued for analysis'),
            'analyzing' => __('Analyzing your repository'),
            'report_ready', 'sent' => __('Your report is ready'),
            'failed' => __('The analysis hit a snag — an engineer is taking a look'),
            'needs_followup', 'awaiting_access' => __('We need access to your repository — check your email'),
            'not_analyzable' => __("We couldn't reach your repository — check your email for next steps"),
            'awaiting_payment' => __('Payment needed to continue — check your email for options'),
            'awaiting_credit' => __('This repository needs more audit credit — check your email for next steps.'),
            'expert_review' => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
            default => __('Processing'),
        };
    }
}
