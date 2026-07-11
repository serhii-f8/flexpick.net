<?php

namespace App\Jobs;

use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RouteVerifiedAuditRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function handle(AuditRequestService $service): void
    {
        $service->routeVerified($this->auditRequest);
    }
}
