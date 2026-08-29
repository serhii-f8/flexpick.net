<?php

namespace App\Services\AuditReport;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScoreChartBuilder
{
    /**
     * @param  Collection<int, int>  $scores  oldest-to-newest
     * @param  Collection<int, Carbon>  $dates  same order/length as $scores
     * @return list<ScoreChartPoint>
     */
    public function build(Collection $scores, Collection $dates): array
    {
        if ($scores->count() < 2) {
            return [];
        }

        // Audit scores are always 0-100, so the axis is too. Scaling to the
        // observed max instead would pin a repo's personal best to the top of
        // its own chart -- a 75 drawn exactly where another repo's 100 sits --
        // and would put the 25/50/75 gridlines on meaningless offsets.
        $max = 100;
        $step = 200 / max(1, $scores->count() - 1);
        $points = [];
        $previous = null;

        foreach ($scores->values() as $i => $score) {
            $delta = $previous !== null ? $score - $previous : null;
            $colorClass = $delta === null || $delta === 0
                ? 'text-gray-400'
                : ($delta > 0 ? 'text-emerald-500' : 'text-rose-500');

            $date = $dates->get($i);
            $tooltip = $previous !== null
                ? sprintf('%d → %d (%+d) on %s', $previous, $score, $delta, $date?->format('M j, Y'))
                : sprintf('%d on %s', $score, $date?->format('M j, Y'));

            $points[] = new ScoreChartPoint(
                x: round($i * $step, 2),
                y: round(34 - ($score / $max) * 30, 2),
                score: $score,
                delta: $delta,
                colorClass: $colorClass,
                tooltip: $tooltip,
            );

            $previous = $score;
        }

        return $points;
    }
}
