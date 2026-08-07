<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Collection;

/**
 * Ordering and grouping for report findings.
 *
 * This logic previously lived in a @php block at the top of the
 * deep-findings partial. It moved here so the web and PDF renderings cannot
 * drift apart, and so the ordering is testable without rendering a view.
 */
class ReportPresenter
{
    public const SEVERITY_RANK = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1,
    ];

    /**
     * Findings grouped by file; files ordered by their worst finding, findings
     * within a file by severity, then line. A customer opens one file and sees
     * everything wrong with it instead of jumping around a flat severity list.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function findingsByFile(array $payload): Collection
    {
        /** @var Collection<int, array<string, mixed>> $sorted */
        $sorted = collect($payload['file_findings'] ?? [])
            // Arrays compare element-wise in PHP, so negating the rank sorts
            // severity descending and line ascending in one pass.
            ->sortBy(fn (array $f): array => [-$this->rank($f), $f['line'] ?? 0]);

        /** @var Collection<string, Collection<int, array<string, mixed>>> $grouped */
        $grouped = $sorted->groupBy('path');

        return $grouped->sortByDesc(fn (Collection $findings): int => $findings->max(fn (array $f): int => $this->rank($f)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function deepReviewMeta(array $payload): ?array
    {
        return $payload['deep_review'] ?? null;
    }

    /** @param  array<string, mixed>  $finding */
    private function rank(array $finding): int
    {
        return self::SEVERITY_RANK[$finding['severity'] ?? ''] ?? 0;
    }
}
