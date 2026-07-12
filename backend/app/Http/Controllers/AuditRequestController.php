<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuditRequestRequest;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Models\AuditRequest;
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
}
