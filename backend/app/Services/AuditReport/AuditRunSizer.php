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

        $runs = $this->bands->runsFor($totalLoc);

        if ($runs === null) {
            throw AuditAwaitingCreditException::tooLarge(__(
                'This repository has about :loc lines of code, above the :max-line limit for self-serve audits.',
                ['loc' => number_format($totalLoc), 'max' => number_format($this->bands->ceiling())],
            ));
        }

        DB::transaction(function () use ($auditRequest, $runs, $totalLoc): void {
            // funding = null: an operator created or comp'd this run, and
            // decided its cost themselves.
            if ($auditRequest->funding !== null && ! $this->entitlements->chargeExtraRuns($auditRequest, $runs - 1)) {
                throw AuditAwaitingCreditException::insufficient(__(
                    'This repository has about :loc lines of code, so a :tier audit takes :runs runs, and your workspace doesn\'t have enough available.',
                    ['loc' => number_format($totalLoc), 'tier' => $auditRequest->tier?->label() ?? '', 'runs' => $runs],
                ));
            }

            $auditRequest->update(['run_count' => $runs]);
        });

        return $runs;
    }
}
