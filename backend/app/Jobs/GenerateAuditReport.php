<?php

namespace App\Jobs;

use App\Models\AuditRequest;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateAuditReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public AuditRequest $auditRequest,
    ) {
        $this->onConnection('redis-audit');
        $this->onQueue(config('audit.queue'));
    }

    public function handle(AuditPipeline $pipeline): void
    {
        $pipeline->run($this->auditRequest);
    }

    public function failed(?Throwable $exception): void
    {
        app(AuditRequestService::class)->markFailed(
            $this->auditRequest,
            $exception?->getMessage() ?? 'Unknown pipeline failure',
        );
    }
}
