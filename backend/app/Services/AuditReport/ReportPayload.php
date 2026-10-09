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
    public const VERSION = 6;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    private const V1_SCORES = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'];

    private const FINDING_CATEGORIES = ['business_logic', 'authorization', 'architecture', 'security'];

    private const EFFORTS = ['S', 'M', 'L'];

    /** Score dimensions the plain-language areas may describe; never `overall`. */
    private const AREA_DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public const URGENCIES = ['now', 'soon', 'later'];

    public const BUSINESS_AREAS = ['customers', 'costs', 'security', 'speed'];

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
            4 => self::validateV4($payload),
            5 => self::validateV5($payload),
            6 => self::validateV6($payload),
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

    private static function validateV4(array $payload): array
    {
        $payload = self::validateV3($payload);

        // Optional by design, matching file_findings/deep_review — the
        // validator is context-free and must not learn about tiers.
        if (array_key_exists('expert_review', $payload)) {
            self::validateExpertReview($payload['expert_review']);
        }

        return $payload;
    }

    private static function validateV5(array $payload): array
    {
        $payload = self::validateV4($payload);

        // Optional for the same reason as every section since v3: reports
        // stored before the plain-language summary existed must keep
        // validating on view.
        if (array_key_exists('client_summary', $payload)) {
            self::validateClientSummary($payload['client_summary']);
        }

        return $payload;
    }

    private static function validateV6(array $payload): array
    {
        $payload = self::validateV5($payload);

        // Every v6 field is optional here for the same reason as every
        // section since v3: a v5 report must keep validating on view. The
        // Claude schema is where they are required.
        if (! array_key_exists('client_summary', $payload)) {
            return $payload;
        }

        $summary = $payload['client_summary'];

        if (array_key_exists('verdict', $summary) && ! is_string($summary['verdict'])) {
            throw new AiAnalysisException('Malformed client_summary verdict');
        }

        foreach ($summary['findings'] as $finding) {
            if (array_key_exists('urgency', $finding) && ! in_array($finding['urgency'], self::URGENCIES, true)) {
                throw new AiAnalysisException('Malformed client_summary finding: urgency');
            }

            if (array_key_exists('business_area', $finding) && ! in_array($finding['business_area'], self::BUSINESS_AREAS, true)) {
                throw new AiAnalysisException('Malformed client_summary finding: business_area');
            }
        }

        if (array_key_exists('areas', $summary)) {
            if (! is_array($summary['areas'])) {
                throw new AiAnalysisException('Malformed client_summary areas');
            }

            foreach ($summary['areas'] as $area) {
                if (! is_array($area)
                    || ! in_array($area['area'] ?? null, self::AREA_DIMENSIONS, true)
                    || ! is_string($area['meaning'] ?? null)
                    || ! is_string($area['status'] ?? null)) {
                    throw new AiAnalysisException('Malformed client_summary area entry');
                }
            }

            // A dimension absent from scores was not measured on this run.
            // Describing it would tell the owner something nobody checked,
            // so the entry goes -- the report itself is still sound.
            $payload['client_summary']['areas'] = array_values(array_filter(
                $summary['areas'],
                fn (array $area): bool => array_key_exists($area['area'], $payload['scores'] ?? []),
            ));
        }

        if (array_key_exists('roadmap', $summary)) {
            if (! is_array($summary['roadmap'])) {
                throw new AiAnalysisException('Malformed client_summary roadmap');
            }

            foreach ($summary['roadmap'] as $step) {
                if (! is_array($step)
                    || ! is_string($step['step'] ?? null)
                    || ! is_string($step['outcome'] ?? null)
                    || ! in_array($step['effort'] ?? null, self::EFFORTS, true)) {
                    throw new AiAnalysisException('Malformed client_summary roadmap step');
                }
            }
        }

        if (array_key_exists('questions', $summary)) {
            if (! is_array($summary['questions'])) {
                throw new AiAnalysisException('Malformed client_summary questions');
            }

            foreach ($summary['questions'] as $question) {
                if (! is_string($question)) {
                    throw new AiAnalysisException('Malformed client_summary question');
                }
            }
        }

        return $payload;
    }

    /**
     * The section written for a non-technical reader: a short overview and
     * a handful of findings, each as what is wrong, what it may cause, and
     * what fixing it gains the client.
     */
    private static function validateClientSummary(mixed $summary): void
    {
        if (! is_array($summary)
            || ! is_string($summary['overview'] ?? null)
            || ! is_array($summary['findings'] ?? null)) {
            throw new AiAnalysisException('Malformed client_summary section');
        }

        foreach ($summary['findings'] as $finding) {
            if (! is_array($finding)
                || ! is_string($finding['what'] ?? null)
                || ! is_string($finding['consequence'] ?? null)
                || ! is_string($finding['gain'] ?? null)) {
                throw new AiAnalysisException('Malformed client_summary finding entry');
            }
        }
    }

    private static function validateExpertReview(mixed $review): void
    {
        if (! is_array($review)
            || ! is_string($review['expert_summary'] ?? null)
            || ! is_string($review['review_notes'] ?? null)
            || ! is_string($review['reviewed_by'] ?? null)
            || ! is_string($review['reviewed_at'] ?? null)) {
            throw new AiAnalysisException('Malformed expert_review section');
        }
    }
}
