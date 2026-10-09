<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Support\ScoreBand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles everything the business report shows, once, for both the web
 * page and the PDF -- so the two can never disagree about a number.
 *
 * Every v6 field is optional on stored payloads; whatever is missing comes
 * back null and the views hide that section.
 */
class BusinessReportPresenter
{
    public function __construct(private ReportChartBuilder $charts) {}

    /**
     * @param  array{previous_at: Carbon, deltas: array<string, int>}|null  $deltas
     * @param  Collection<int, AuditFindingGroup>  $groups
     * @return array<string, mixed>
     */
    public function present(AuditReport $report, ?array $deltas, ?int $percentile, Collection $groups): array
    {
        $payload = $report->payload;
        $summary = $payload['client_summary'] ?? null;
        $scores = array_diff_key($payload['scores'] ?? [], ['overall' => true]);
        $notMeasured = (array) ($report->auditRequest?->metrics['not_measured'] ?? []);
        $overall = (int) ($payload['scores']['overall'] ?? 0);
        $findings = $this->findings($summary['findings'] ?? []);
        $hasBusinessAreas = collect($findings)->contains(fn (array $f): bool => $f['business_area'] !== null);

        return [
            'overall' => $overall,
            'band' => ScoreBand::fromScore($overall),
            'verdict' => $summary['verdict'] ?? null,
            'overview' => $summary['overview'] ?? null,
            'hasClientSummary' => $summary !== null,
            'delta' => ($deltas['deltas']['overall'] ?? 0) !== 0 ? $deltas['deltas']['overall'] : null,
            'previousAt' => $deltas['previous_at'] ?? null,
            'percentile' => $percentile,
            'areas' => $this->areas($scores, $notMeasured, $summary['areas'] ?? []),
            'findings' => $findings,
            'roadmap' => isset($summary['roadmap']) ? $this->roadmap($summary['roadmap']) : null,
            'questions' => $summary['questions'] ?? null,
            'expertSummary' => $payload['expert_review']['expert_summary'] ?? null,
            'charts' => [
                'gauge' => $this->charts->scoreGauge($overall),
                'percentile' => $percentile !== null ? $this->charts->percentileBar($percentile) : null,
                'areas' => $this->charts->areaBars($scores, $notMeasured),
                'severity' => $this->charts->severityStack($this->severityCounts($groups, $payload['groups'] ?? [])),
                'businessAreas' => $hasBusinessAreas ? $this->charts->businessAreaBars($this->businessAreaCounts($findings)) : null,
            ],
        ];
    }

    /** @return list<array{key: string, label: string, score: ?int, meaning: ?string, status: ?string}> */
    private function areas(array $scores, array $notMeasured, array $described): array
    {
        $text = collect($described)->keyBy('area');
        $areas = [];

        foreach (ReportChartBuilder::DIMENSIONS as $dimension) {
            if (! array_key_exists($dimension, $scores) && ! in_array($dimension, $notMeasured, true)) {
                continue;
            }

            $areas[] = [
                'key' => $dimension,
                'label' => __(ReportChartBuilder::AREA_LABELS[$dimension]),
                'score' => array_key_exists($dimension, $scores) ? (int) $scores[$dimension] : null,
                'meaning' => $text[$dimension]['meaning'] ?? null,
                'status' => $text[$dimension]['status'] ?? null,
            ];
        }

        return $areas;
    }

    private function findings(array $findings): array
    {
        return array_map(fn (array $f): array => [
            'what' => $f['what'],
            'consequence' => $f['consequence'],
            'gain' => $f['gain'],
            'urgency' => $f['urgency'] ?? null,
            'urgency_label' => match ($f['urgency'] ?? null) {
                'now' => __('Fix now'),
                'soon' => __('Fix soon'),
                'later' => __('Can wait'),
                default => null,
            },
            'business_area' => $f['business_area'] ?? null,
        ], $findings);
    }

    private function roadmap(array $steps): array
    {
        return array_map(fn (array $s): array => $s + [
            'effort_label' => match ($s['effort']) {
                'S' => __('A few days'),
                'M' => __('A couple of weeks'),
                default => __('A month or more'),
            },
        ], $steps);
    }

    /** @return array<string, int> */
    private function severityCounts(Collection $groups, array $payloadGroups): array
    {
        // One problem area is one group, however many lines it touches: an
        // owner reads "3 urgent problems", not "214 lint hits".
        $source = $groups->isNotEmpty()
            ? $groups->map(fn ($g): string => $g->severity)
            : collect($payloadGroups)->pluck('severity');

        return $source->countBy()->all();
    }

    /** @return array<string, int> */
    private function businessAreaCounts(array $findings): array
    {
        return collect($findings)->pluck('business_area')->filter()->countBy()->all();
    }
}
