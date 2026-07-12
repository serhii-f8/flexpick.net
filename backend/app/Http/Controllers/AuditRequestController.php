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
use App\Services\AuditRequestService;
use Illuminate\Http\JsonResponse;

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

    public function verify(AuditRequest $auditRequest, AuditFunnelRecorder $funnel)
    {
        if ($auditRequest->email_verified_at === null) {
            $auditRequest->update(['email_verified_at' => now()]);
            $funnel->record(AuditFunnelRecorder::STAGE_VERIFIED, $auditRequest);
            RouteVerifiedAuditRequest::dispatch($auditRequest);
        }

        return view('audit.verified', ['auditRequest' => $auditRequest]);
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
}
