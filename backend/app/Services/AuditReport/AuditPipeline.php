<?php

namespace App\Services\AuditReport;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\AuditRequest;
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
    ) {}

    public function run(AuditRequest $auditRequest): void
    {
        $auditRequest->update(['status' => AuditRequestStatus::ANALYZING->value]);

        try {
            $this->cloner->preflight($auditRequest->repo_url);
            $path = $this->cloner->clone($auditRequest->repo_url, $auditRequest->uuid);

            $collected = $this->metricsCollector->collect($path);
            $metrics = $collected['metrics'];
            $scores = $this->scoreCalculator->calculate($metrics);
            $metrics['computed_scores'] = $scores;
            $auditRequest->update(['metrics' => $metrics]);

            $payload = $this->analyzer->analyze($metrics, $collected['excerpts'], $auditRequest->admin_context);
            $payload['scores'] = $scores;

            $report = $this->reportService->create($auditRequest, $payload);
            $this->reportService->send($report);
        } catch (AuditNotAnalyzableException $e) {
            $this->requestService->markNeedsFollowup($auditRequest, $e->getMessage());
        } finally {
            $this->cloner->cleanup($auditRequest->uuid);
        }
    }
}
