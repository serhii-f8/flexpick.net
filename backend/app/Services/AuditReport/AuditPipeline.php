<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingDeduplicator;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccScanner;
use App\Services\AuditReport\Scanners\ScannerRunner;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use App\Services\AuditRequestService;

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
    ) {}

    public function run(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now(),
        ]);
        $auditRequest->appendPipelineLog('started', 'Analysis started');

        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($auditRequest): void {
            $scope->setTag('audit_request', (string) $auditRequest->uuid);
        });

        try {
            $this->cloner->preflight($auditRequest->repo_url);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid);
            $auditRequest->appendPipelineLog('cloned', 'Repository cloned');

            $profile = $this->tierProfileResolver->for($auditRequest->tier);
            $context = new RepoContext($path, $profile);

            // Scanners first — scc's inventory sizes the budgets for everything
            // after it, including excerpt selection (spec §3.2).
            $suite = $this->scannerRunner->run($profile->scanners, $context);
            $this->logScannerOutcomes($auditRequest, $suite);

            // scc failing must not leave later stages without a basis (spec §10).
            if ($context->inventory === null) {
                $context->withInventory($this->sccScanner->fallbackInventory($path));
                $auditRequest->appendPipelineLog('inventory', 'scc unavailable; used a walked file inventory');
            }

            $groups = $this->grouper->group($this->deduplicator->dedupe($suite->findings));

            $collected = $this->metricsCollector->collect($context);
            $metrics = $collected['metrics'];
            // Recorded by JscpdScanner on the per-run context (Task 12).
            $metrics['duplication_pct'] = (float) $context->measurement('duplication_pct', 0.0);

            $scoreSet = $this->scoreCalculator->calculate($metrics, $groups, $suite);
            $metrics['computed_scores'] = $scoreSet->toPayloadScores();
            $metrics['not_measured'] = $scoreSet->notMeasured;

            $auditRequest->update([
                'metrics' => $metrics,
                'scanner_runs' => $suite->runsAsArray(),
            ]);
            $this->persistGroups($auditRequest, $groups);
            $auditRequest->appendPipelineLog('metrics', 'Metrics collected, findings grouped and scored');

            $result = $this->analyzer->analyze(
                $metrics,
                array_slice($groups, 0, $profile->narratedGroups),
                $collected['excerpts'],
                $profile,
                $auditRequest->admin_context,
            );

            $payload = $result->payload;
            $payload['scores'] = $scoreSet->toPayloadScores();
            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            $report = $this->reportService->create($auditRequest, $payload, $scoreSet->scoringVersion);
            $this->reportService->send($report);

            $auditRequest->update(['analysis_completed_at' => now()]);
            $auditRequest->appendPipelineLog('report', 'Report stored and sent');
        } catch (AuditNotAnalyzableException $e) {
            $auditRequest->appendPipelineLog('not_analyzable', $e->getMessage());
            $this->requestService->markNeedsFollowup($auditRequest, $e->getMessage());
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

    /** @param list<\App\Services\AuditReport\Findings\FindingGroup> $groups */
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
}
