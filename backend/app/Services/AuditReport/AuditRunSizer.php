<?php

namespace App\Services\AuditReport;

use App\Exceptions\AuditAwaitingCreditException;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\DB;

/**
 * Settles how many runs an audit costs, once the repository has been cloned
 * and counted, and charges whatever that adds beyond the first run.
 */
class AuditRunSizer
{
    public function __construct(
        private AuditSizeBands $bands,
        private AuditEntitlementService $entitlements,
    ) {}

    /** @throws AuditAwaitingCreditException */
    public function settle(AuditRequest $auditRequest, int $totalLoc): int
    {
        // Settled once per request. The queue's retries and the admin's
        // "Retry pipeline" re-enter from the clone; charging again there
        // would bill one audit twice.
        if ($auditRequest->run_count !== null) {
            return $auditRequest->run_count;
        }

        // Everything read from `configs` is resolved here, before the
        // transaction opens. Lock order inside it is audit_request -> tenant
        // -> tenant_parameter, and the invariant that keeps the metering
        // honest is that no plain (non-locking) read runs in the transaction
        // before the tenant row lock: under REPEATABLE READ the first plain
        // SELECT fixes the snapshot, and a snapshot fixed before that lock
        // would hide runs a concurrent sizing of the same workspace committed
        // while we waited for it. The request re-read below is a locking
        // read, which opens no snapshot. See chargeExtraRuns().
        $runs = $this->bands->runsFor($totalLoc);
        $ceiling = $runs === null ? $this->bands->ceiling() : null;

        return DB::transaction(function () use ($auditRequest, $totalLoc, $runs, $ceiling): int {
            // Two workers can run this request's retries at once, each with
            // its own stale copy that still shows a null run_count. Re-read
            // the row under a write lock and recheck, so the settle and its
            // charges happen exactly once.
            $settled = AuditRequest::whereKey($auditRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($settled->run_count !== null) {
                $auditRequest->refresh();

                return $settled->run_count;
            }

            if ($runs === null) {
                throw AuditAwaitingCreditException::tooLarge(__(
                    'This repository has about :loc lines of code, above the :max-line limit for self-serve audits.',
                    ['loc' => number_format($totalLoc), 'max' => number_format((int) $ceiling)],
                ));
            }

            // funding = null: an operator created or comp'd this run, and
            // decided its cost themselves.
            if ($settled->funding !== null && ! $this->entitlements->chargeExtraRuns($settled, $runs - 1)) {
                throw AuditAwaitingCreditException::insufficient($this->insufficientMessage($settled, $runs, $totalLoc));
            }

            $settled->update(['run_count' => $runs]);
            $auditRequest->refresh();

            return $runs;
        });
    }

    private function insufficientMessage(AuditRequest $auditRequest, int $runs, int $totalLoc): string
    {
        if ($auditRequest->tier === null) {
            return __(
                'This repository has about :loc lines of code, so an audit takes :runs runs, and your workspace doesn\'t have enough available.',
                ['loc' => number_format($totalLoc), 'runs' => $runs],
            );
        }

        return __(
            'This repository has about :loc lines of code, so a :tier audit takes :runs runs, and your workspace doesn\'t have enough available.',
            ['loc' => number_format($totalLoc), 'tier' => $auditRequest->tier->label(), 'runs' => $runs],
        );
    }
}
