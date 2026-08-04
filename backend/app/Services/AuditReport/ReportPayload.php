<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;

/**
 * The canonical payload contract.
 *
 * validate() dispatches on version and retains v1 so historical reports keep
 * rendering — AuditReportController validates stored payloads on every view
 * (spec §7.4).
 */
class ReportPayload
{
    /** Bump when the payload contract changes. */
    public const VERSION = 3;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    private const V1_SCORES = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'];

    private const FINDING_CATEGORIES = ['business_logic', 'authorization', 'architecture', 'security'];

    private const EFFORTS = ['S', 'M', 'L'];

    public static function validate(mixed $payload, ?int $version = null): array
    {
        $version ??= self::VERSION;

        if (! is_array($payload)) {
            throw new AiAnalysisException('Analysis payload is not an object');
        }

        self::validateCommon($payload);

        return match ($version) {
            1 => self::validateV1($payload),
            2 => self::validateV2($payload),
            3 => self::validateV3($payload),
            default => throw new AiAnalysisException("Unknown payload schema version: {$version}"),
        };
    }

    private static function validateCommon(array $payload): void
    {
        if (! is_string($payload['summary'] ?? null)) {
            throw new AiAnalysisException('Missing summary');
        }

        if (! is_array($payload['risks'] ?? null)) {
            throw new AiAnalysisException('Missing risks');
        }

        foreach ($payload['risks'] as $risk) {
            if (! in_array($risk['impact'] ?? null, ['high', 'medium', 'low'], true)
                || ! is_string($risk['title'] ?? null)
                || ! is_string($risk['evidence'] ?? null)
                || ! is_string($risk['recommendation'] ?? null)) {
                throw new AiAnalysisException('Malformed risk entry');
            }
        }

        if (! is_array($payload['fix_first_plan'] ?? null)) {
            throw new AiAnalysisException('Missing fix_first_plan');
        }

        foreach ($payload['fix_first_plan'] as $step) {
            if (! is_string($step['step'] ?? null)
                || ! is_string($step['why'] ?? null)
                || ! in_array($step['effort'] ?? null, ['S', 'M', 'L'], true)) {
                throw new AiAnalysisException('Malformed fix_first_plan entry');
            }
        }
    }

    private static function validateV1(array $payload): array
    {
        foreach (self::V1_SCORES as $key) {
            if (! is_int($payload['scores'][$key] ?? null)) {
                throw new AiAnalysisException("Missing or non-integer score: {$key}");
            }
        }

        return $payload;
    }

    private static function validateV2(array $payload): array
    {
        // Dimensions may be absent when their scanner did not run (spec §7.2);
        // `overall` is always present because it renormalizes over what ran.
        if (! is_int($payload['scores']['overall'] ?? null)) {
            throw new AiAnalysisException('Missing or non-integer score: overall');
        }

        foreach ($payload['scores'] ?? [] as $key => $value) {
            if (! in_array($key, self::V1_SCORES, true)) {
                throw new AiAnalysisException("Unknown score dimension: {$key}");
            }

            if (! is_int($value)) {
                throw new AiAnalysisException("Non-integer score: {$key}");
            }
        }

        if (! is_array($payload['groups'] ?? null)) {
            throw new AiAnalysisException('Missing groups');
        }

        foreach ($payload['groups'] as $group) {
            if (! is_string($group['rule_family'] ?? null)
                || ! is_string($group['directory'] ?? null)
                || ! in_array($group['severity'] ?? null, self::SEVERITIES, true)
                || ! is_int($group['count'] ?? null)
                || ! is_string($group['narrative']['what'] ?? null)
                || ! is_string($group['narrative']['affects'] ?? null)
                || ! is_string($group['narrative']['benefit'] ?? null)) {
                throw new AiAnalysisException('Malformed group entry');
            }
        }

        return $payload;
    }

    private static function validateV3(array $payload): array
    {
        $payload = self::validateV2($payload);

        // Both deep keys are OPTIONAL by design. The validator is context-free
        // and must not learn about tiers — and degradation has to yield a
        // VALID payload with file_findings absent (spec D1).
        foreach ($payload['file_findings'] ?? [] as $finding) {
            self::validateFileFinding($finding);
        }

        if (array_key_exists('deep_review', $payload)) {
            self::validateDeepReviewMeta($payload['deep_review']);
        }

        return $payload;
    }

    private static function validateFileFinding(mixed $finding): void
    {
        if (! is_array($finding)
            || ! is_string($finding['path'] ?? null)
            || ! is_string($finding['title'] ?? null)
            || ! is_string($finding['evidence'] ?? null)
            || ! is_string($finding['recommendation'] ?? null)
            || ! in_array($finding['severity'] ?? null, self::SEVERITIES, true)
            || ! in_array($finding['category'] ?? null, self::FINDING_CATEGORIES, true)
            || ! in_array($finding['effort'] ?? null, self::EFFORTS, true)) {
            throw new AiAnalysisException('Malformed file finding entry');
        }

        if (array_key_exists('line', $finding) && $finding['line'] !== null && ! is_int($finding['line'])) {
            throw new AiAnalysisException('Malformed file finding entry: line');
        }

        if (! is_array($finding['related_paths'] ?? [])) {
            throw new AiAnalysisException('Malformed file finding entry: related_paths');
        }

        foreach ($finding['related_paths'] ?? [] as $related) {
            if (! is_string($related)) {
                throw new AiAnalysisException('Malformed file finding entry: related_paths');
            }
        }
    }

    private static function validateDeepReviewMeta(mixed $meta): void
    {
        if (! is_array($meta)) {
            throw new AiAnalysisException('Malformed deep_review metadata');
        }

        foreach (['files_selected', 'files_reviewed', 'selection_version'] as $key) {
            if (array_key_exists($key, $meta) && ! is_int($meta[$key])) {
                throw new AiAnalysisException("Malformed deep_review metadata: {$key}");
            }
        }

        foreach (['truncated', 'degraded'] as $key) {
            if (array_key_exists($key, $meta) && ! is_bool($meta[$key])) {
                throw new AiAnalysisException("Malformed deep_review metadata: {$key}");
            }
        }
    }
}
