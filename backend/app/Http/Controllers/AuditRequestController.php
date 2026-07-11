<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuditRequestRequest;
use App\Services\AuditRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
