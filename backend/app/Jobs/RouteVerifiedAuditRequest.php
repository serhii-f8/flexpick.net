<?php

namespace App\Jobs;

use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RouteVerifiedAuditRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Worst case is a 25s refresh-lock wait + 15s token refresh + 30s ls-remote (70s). Must stay
    // below the default `redis` connection's retry_after (90s) or a hung run is re-released to a
    // second worker while the first still runs; this $timeout supersedes the Horizon supervisor's.
    public int $timeout = 80;

    // A transient git-provider failure during preflight retries (as GenerateAuditReport does).
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public AuditRequest $auditRequest,
    ) {}

    public function handle(AuditRequestService $service): void
    {
        $service->routeVerified($this->auditRequest);
    }

    /**
     * Retries exhausted: surface the request as FAILED (customer and operator are told, as
     * for a failed report run) rather than leaving it verified but never routed. Nothing
     * was charged, so nothing is refunded.
     */
    public function failed(?Throwable $exception): void
    {
        app(AuditRequestService::class)->markFailed(
            $this->auditRequest,
            $exception?->getMessage() ?? 'Unknown routing failure',
        );
    }
}
