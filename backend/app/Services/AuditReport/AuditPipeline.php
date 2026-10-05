<?php

namespace App\Services\AuditReport;

use App\Constants\AuditAiStage;
use App\Constants\AuditRequestStatus;
use App\Exceptions\AiAnalysisException;
use App\Exceptions\AuditAwaitingCreditException;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Notifications\OperationsAlert;
use App\Services\AuditReport\DeepReview\DeepFindingSanitizer;
use App\Services\AuditReport\DeepReview\DeepReviewer;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Tiers\TierProfile;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Notification;
use Sentry\State\Scope;

class AuditPipeline
{
    public function __construct(
        private RepositoryCloner $cloner,
        private MetricsCollector $metricsCollector,
        private AiAnalyzer $analyzer,
        private AuditReportService $reportService,
        private AuditRequestService $requestService,
        private ScoreCalculator $scoreCalculator,
        private TierProfileResolver $tierProfileResolver,
        private ScannerRunner $scannerRunner,
        private FindingDeduplicator $deduplicator,
        private FindingGrouper $grouper,
        private SccScanner $sccScanner,
        private RiskFileSelector $riskFileSelector,
        private DeepReviewer $deepReviewer,
        private DeepFindingSanitizer $sanitizer,
        private AiCallRecorder $aiCalls,
        private AuditRunSizer $runSizer,
    ) {}

    private function elapsedMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }

    public function run(AuditRequest $auditRequest): void
    {
        // A retried job must never reprocess a request an earlier attempt
        // already closed and refunded: a second pass would spend that refund
        // on a fresh charge and re-email a customer who has been told. Only
        // the refunded terminal closes are off-limits — a retry after a
        // delivery failure is expected to re-run and re-bill.
        $currentStatus = AuditRequest::query()->whereKey($auditRequest->getKey())->value('status');

        if (in_array($currentStatus, [
            AuditRequestStatus::AWAITING_CREDIT->value,
            AuditRequestStatus::NOT_ANALYZABLE->value,
        ], true)) {
            return;
        }

        $auditRequest->update([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now(),
        ]);
        $auditRequest->appendPipelineLog('started', 'Analysis started');

        \Sentry\configureScope(function (Scope $scope) use ($auditRequest): void {
            $scope->setTag('audit_request', (string) $auditRequest->uuid);
        });

        try {
            $this->cloner->preflight($auditRequest->repo_url, tenant: $auditRequest->tenant);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid, tenant: $auditRequest->tenant, branch: $auditRequest->branch);
            $auditRequest->appendPipelineLog('cloned', 'Repository cloned');

            $profile = $this->tierProfileResolver->for($auditRequest->tier);
            $context = new RepoContext($path, $profile);

            // Scanners first — scc's inventory sizes the budgets for everything
            // after it, including excerpt selection (spec §3.2). scc runs alone
            // and the request is sized off its count before any other scanner
            // is paid for: an oversized or uncovered request closes here
            // (spec §2).
            $sccSuite = $this->scannerRunner->run(['scc'], $context);

            // scc failing must not leave later stages without a basis (spec §10).
            $inventory = $context->inventory;
            $fellBack = $inventory === null;
            if ($inventory === null) {
                $inventory = $this->sccScanner->fallbackInventory($path);
                $context->withInventory($inventory);
                $auditRequest->appendPipelineLog('inventory', 'scc unavailable; used a walked file inventory');
            }

            // Billed on scc's code count only. A walked inventory (or an scc
            // whose sizing run failed) carries none, and the sizer never
            // charges extras off it.
            $size = $inventory->billableCode === null
                ? AuditSize::unavailable($fellBack ? 'scc unavailable, failed or timed out' : 'scc code count unavailable')
                : AuditSize::measured($inventory->billableCode);

            $runs = $this->runSizer->settle($auditRequest, $size);
            $auditRequest->appendPipelineLog('sized', $size->isMeasured()
                ? sprintf('%s lines of code; %d run(s)', number_format((int) $size->codeLoc), $runs)
                : sprintf('%d run(s); size not measured', $runs));

            $suite = $sccSuite->merge($this->scannerRunner->run(
                array_values(array_filter($profile->scanners, fn (string $name): bool => $name !== 'scc')),
                $context,
            ));
            $this->logScannerOutcomes($auditRequest, $suite);

            $groups = $this->grouper->group($this->deduplicator->dedupe($suite->findings));

            // Q17: excerpt collection (every tier) and risk-file selection
            // both read this. Derived from findings, not from the scanner.
            $context->withSecretPaths($this->secretPaths($suite));

            $collected = $this->metricsCollector->collect($context);
            $metrics = $collected['metrics'];
            // Recorded by JscpdScanner on the per-run context (Task 12).
            $metrics['duplication_pct'] = (float) $context->measurement('duplication_pct', 0.0);

            $scoreSet = $this->scoreCalculator->calculate($metrics, $groups, $suite);
            $metrics['computed_scores'] = $scoreSet->toPayloadScores();
            $metrics['not_measured'] = $scoreSet->notMeasured;
            $metrics['not_measured_reasons'] = $scoreSet->notMeasuredReasons;

            $auditRequest->update([
                'metrics' => $metrics,
                'scanner_runs' => $suite->runsAsArray(),
            ]);
            $this->persistGroups($auditRequest, $groups);
            $auditRequest->appendPipelineLog('metrics', 'Metrics collected, findings grouped and scored');

            $startedAt = microtime(true);

            try {
                $result = $this->analyzer->analyze(
                    $metrics,
                    array_slice($groups, 0, $profile->narratedGroups),
                    $collected['excerpts'],
                    $profile,
                    $auditRequest->admin_context,
                );
            } catch (\Throwable $e) {
                // The provider bills a call it answered even when the SDK
                // raises on the way back (a client-side timeout on a response
                // the model already generated is the common case). Record the
                // attempt before rethrowing, so the retry that follows is
                // visibly the second billed call and not the first.
                $this->aiCalls->recordFailure(
                    $auditRequest,
                    AuditAiStage::ANALYSIS,
                    $e::class,
                    $this->elapsedMs($startedAt),
                );

                throw $e;
            }

            $payload = $result->payload;
            $payload['scores'] = $scoreSet->toPayloadScores();

            // Cost telemetry is written HERE, not after delivery. The model
            // call is billed the moment it returns, and delivery is the
            // stage most likely to fail (PDF render, mail transport) — a
            // failure there re-runs the whole pipeline from the clone and
            // bills again. Writing spend after delivery therefore recorded
            // only the runs that never needed re-running.
            $this->aiCalls->recordSuccess(
                $auditRequest,
                AuditAiStage::ANALYSIS,
                $result->inputTokens,
                $result->outputTokens,
                $this->elapsedMs($startedAt),
            );

            $auditRequest->update([
                'ai_input_tokens' => $result->inputTokens,
                'ai_output_tokens' => $result->outputTokens,
                'scanner_ms' => $suite->totalWallMs(),
                'repo_size_kb' => $this->cloner->sizeKb($path),
            ]);

            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            // The tier-1 payload is complete and valid at this point, which is
            // what lets a deep-review failure lose a SECTION rather than a
            // report (spec D1).
            if ($profile->deepReview !== null) {
                $payload = $this->runDeepReview(
                    $auditRequest,
                    $profile,
                    $context,
                    $this->deduplicator->dedupe($suite->findings),
                    $metrics,
                    array_slice($groups, 0, $profile->narratedGroups),
                    $payload,
                );
            }

            $this->reportService->createAndDeliver($auditRequest, $payload, $scoreSet->scoringVersion);

            // Only the completion stamp stays behind delivery: it marks the
            // run as done for the stuck-run scopes and health checks, which
            // is exactly what it must not claim while a report is unsent.
            $auditRequest->update(['analysis_completed_at' => now()]);
            $auditRequest->appendPipelineLog('report', 'Report stored and sent');
        } catch (AuditAwaitingCreditException $e) {
            // Closed before any other scanner or the AI ran, so the only
            // credit to give back is the first run's (spec §2).
            $auditRequest->appendPipelineLog('awaiting_credit', $e->getMessage());
            $this->requestService->closeAwaitingCredit($auditRequest, $e->getMessage(), $e->tooLarge);
        } catch (AuditNotAnalyzableException $e) {
            $auditRequest->appendPipelineLog('not_analyzable', $e->getMessage());
            $this->requestService->closeNotAnalyzable($auditRequest, $e->getMessage(), $e->accessDenied, $e->reconnect);
        } finally {
            $this->cloner->cleanup($auditRequest->uuid);
        }
    }

    private function logScannerOutcomes(AuditRequest $request, ScannerSuiteResult $suite): void
    {
        foreach ($suite->runs as $run) {
            if ($run->outcome->value === 'ok') {
                continue;
            }

            // Classified reason only — never tool output (spec §5.4).
            $request->appendPipelineLog(
                'scanner_degraded',
                "Scanner {$run->name} did not complete: {$run->reason}",
            );
        }
    }

    /** @param list<FindingGroup> $groups */
    private function persistGroups(AuditRequest $request, array $groups): void
    {
        $request->findingGroups()->delete();

        foreach ($groups as $group) {
            AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $group));
        }
    }

    // No duplicationPercentage() helper: JscpdScanner records the figure on
    // RepoContext during its own scan, and ScoreCalculator marks the
    // duplication dimension not-measured when jscpd did not run — so a
    // missing measurement can never be mistaken for a duplication-free repo.

    /** @return list<string> */
    private function secretPaths(ScannerSuiteResult $suite): array
    {
        $paths = [];

        foreach ($suite->findings as $finding) {
            if ($finding->tool === 'gitleaks') {
                $paths[] = $finding->path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<DedupedFinding>  $dedupedFindings
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runDeepReview(
        AuditRequest $auditRequest,
        TierProfile $profile,
        RepoContext $context,
        array $dedupedFindings,
        array $metrics,
        array $groups,
        array $payload,
    ): array {
        $startedAt = microtime(true);
        $callStartedAt = $startedAt;
        $callInFlight = false;
        $selection = null;

        try {
            $selection = $this->riskFileSelector->select($context, $dedupedFindings, $profile->deepReview);
            $auditRequest->update(['risk_files' => $selection->toLogArray()]);

            $auditRequest->appendPipelineLog('risk_files', sprintf(
                'Selected %d of %d candidates for deep review%s',
                count($selection->files),
                $selection->candidatesConsidered,
                $selection->truncated ? ' (truncated by the token budget)' : '',
            ));

            if ($selection->belowFloor) {
                // Someone paid for "your 20-40 riskiest files" and this repo
                // cannot supply them. Whether that warrants a refund is a human
                // judgement, so it surfaces rather than being absorbed.
                $this->alert($auditRequest, sprintf(
                    'Deep review ran on only %d files, below the configured floor.',
                    count($selection->files),
                ));
            }

            if ($selection->files === []) {
                // Zero candidates survived selection (e.g. everything vendored
                // or generated). Calling the reviewer would return an honest
                // empty result that then looks identical to "reviewed and
                // clean" — a false all-clear for a paying customer, not a
                // healthy zero-findings outcome. belowFloor already alerted
                // above; this only stops the section from rendering as
                // reviewed.
                $auditRequest->appendPipelineLog(
                    'deep_review_degraded',
                    'Deep review skipped: no files survived risk-file selection',
                );

                $payload['deep_review'] = [
                    'files_selected' => $selection->selectedBeforeBudget,
                    'files_reviewed' => 0,
                    'truncated' => $selection->truncated,
                    'selection_version' => $selection->selectionVersion,
                    'degraded' => true,
                ];

                return $payload;
            }

            $callStartedAt = microtime(true);
            $callInFlight = true;
            $review = $this->deepReviewer->review($metrics, $groups, $selection, $profile->deepReview);
            $callInFlight = false;

            // Recorded before the sanitizer and validator run. Both can reject
            // this response — a review whose findings were all fabricated is
            // discarded — but the tokens that produced it are billed either
            // way, and a degraded section is not a free one.
            $this->aiCalls->recordSuccess(
                $auditRequest,
                AuditAiStage::DEEP_REVIEW,
                $review->inputTokens,
                $review->outputTokens,
                $this->elapsedMs($callStartedAt),
            );

            $auditRequest->update([
                'deep_review_input_tokens' => $review->inputTokens,
                'deep_review_output_tokens' => $review->outputTokens,
                'deep_review_ms' => $this->elapsedMs($startedAt),
            ]);

            $sanitized = $this->sanitizer->sanitize(
                $review->findings,
                $selection->paths(),
                array_column($context->inventory?->files ?? [], 'path'),
            );

            if ($sanitized['dropped'] > 0 || $sanitized['strippedRelated'] > 0) {
                $auditRequest->appendPipelineLog('deep_review_sanitized', sprintf(
                    'Dropped %d finding(s) on files that were never sent; stripped %d unknown related path(s)',
                    $sanitized['dropped'],
                    $sanitized['strippedRelated'],
                ));
            }

            // A review whose every finding was fabricated is not a review.
            if ($sanitized['findings'] === [] && $sanitized['dropped'] > 0) {
                throw new AiAnalysisException('Every deep finding referenced a file that was never sent');
            }

            $payload['file_findings'] = $sanitized['findings'];
            $payload['deep_review'] = [
                'files_selected' => $selection->selectedBeforeBudget,
                'files_reviewed' => count($selection->files),
                'truncated' => $selection->truncated,
                'selection_version' => $selection->selectionVersion,
                'degraded' => false,
            ];

            // The tier-1 payload was validated in ClaudeAnalyzer BEFORE
            // file_findings/deep_review existed. Re-validate the merged
            // payload here so a schema drift, provider change, or sanitizer
            // bug can never persist a shape Blade isn't prepared to render —
            // a validation failure degrades this section exactly like any
            // other failure in this stage (caught below).
            $payload = ReportPayload::validate($payload);

            $auditRequest->appendPipelineLog('deep_review', sprintf(
                'Deep review returned %d finding(s)',
                count($sanitized['findings']),
            ));

            return $payload;
        } catch (\Throwable $e) {
            \Sentry\captureException($e);

            // Classified reason only — never raw exception text, which could
            // echo customer source back into an unscrubbed DB log/email
            // (spec §5.4; see logScannerOutcomes() for the same convention).
            $reason = $e::class;

            // Only when the reviewer call itself was in flight. A selection or
            // sanitizer failure is not a billed call, and recording one would
            // put phantom spend in the ledger; a reviewer call that already
            // returned was recorded on the success path above.
            if ($callInFlight) {
                $this->aiCalls->recordFailure(
                    $auditRequest,
                    AuditAiStage::DEEP_REVIEW,
                    $reason,
                    $this->elapsedMs($callStartedAt),
                );
            }

            $auditRequest->appendPipelineLog('deep_review_degraded', 'Deep review did not complete: '.$reason);
            $this->alert($auditRequest, 'Deep review failed: '.$reason);

            // The try block may have already written file_findings before a
            // later failure (e.g. a validate() rejection after sanitizing) —
            // a degraded payload must never carry a finding that was never
            // proven valid.
            unset($payload['file_findings']);

            $payload['deep_review'] = [
                'files_selected' => $selection?->selectedBeforeBudget ?? 0,
                'files_reviewed' => 0,
                'truncated' => $selection?->truncated ?? false,
                'selection_version' => (int) config('audit.deep_review.selection_version'),
                'degraded' => true,
            ];

            return $payload;
        }
    }

    /**
     * A degraded PAID run is an individual actionable event — a health check
     * could report an elevated failure rate but never say which customer's run
     * to re-run.
     */
    private function alert(AuditRequest $auditRequest, string $message): void
    {
        Notification::route('mail', (string) config('audit.admin_email'))->notify(
            new OperationsAlert(
                checkName: 'deep_review',
                band: 'high',
                status: 'failed',
                message: $message.' Audit request: '.$auditRequest->uuid,
            ),
        );
    }
}
