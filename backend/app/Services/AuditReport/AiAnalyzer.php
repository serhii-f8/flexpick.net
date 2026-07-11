<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;

interface AiAnalyzer
{
    /**
     * @param  array  $metrics  output of MetricsCollector ['metrics']
     * @param  array<int, array{path: string, content: string}>  $excerpts
     * @return array validated report payload (see ReportPayload)
     *
     * @throws AiAnalysisException
     */
    public function analyze(array $metrics, array $excerpts): array;
}
