<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;

class ReportPayload
{
    /** Bump when the payload contract changes. validate() dispatches on this. */
    public const VERSION = 1;

    public static function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new AiAnalysisException('Analysis payload is not an object');
        }

        if (! is_string($payload['summary'] ?? null)) {
            throw new AiAnalysisException('Missing summary');
        }

        $scores = $payload['scores'] ?? null;
        foreach (['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'] as $key) {
            if (! is_int($scores[$key] ?? null)) {
                throw new AiAnalysisException("Missing or non-integer score: {$key}");
            }
        }

        foreach ($payload['risks'] ?? [] as $risk) {
            if (! in_array($risk['impact'] ?? null, ['high', 'medium', 'low'], true)
                || ! is_string($risk['title'] ?? null)
                || ! is_string($risk['evidence'] ?? null)
                || ! is_string($risk['recommendation'] ?? null)) {
                throw new AiAnalysisException('Malformed risk entry');
            }
        }
        if (! is_array($payload['risks'] ?? null)) {
            throw new AiAnalysisException('Missing risks');
        }

        foreach ($payload['fix_first_plan'] ?? [] as $step) {
            if (! is_string($step['step'] ?? null)
                || ! is_string($step['why'] ?? null)
                || ! in_array($step['effort'] ?? null, ['S', 'M', 'L'], true)) {
                throw new AiAnalysisException('Malformed fix_first_plan entry');
            }
        }
        if (! is_array($payload['fix_first_plan'] ?? null)) {
            throw new AiAnalysisException('Missing fix_first_plan');
        }

        return $payload;
    }
}
