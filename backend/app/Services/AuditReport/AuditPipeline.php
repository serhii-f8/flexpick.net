<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditRequest;
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
            $collected = $this->metricsCollector->collect($path, $profile);
            $metrics = $collected['metrics'];
            $scores = $this->scoreCalculator->calculate($metrics);
            $metrics['computed_scores'] = $scores;
            $auditRequest->update(['metrics' => $metrics]);
            $auditRequest->appendPipelineLog('metrics', 'Metrics collected and scored');

            $payload = $this->analyzer->analyze($metrics, $collected['excerpts'], $auditRequest->admin_context);
            $payload['scores'] = $scores;
            $auditRequest->appendPipelineLog('analyzed', 'AI analysis finished');

            $report = $this->reportService->create($auditRequest, $payload);
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
}
